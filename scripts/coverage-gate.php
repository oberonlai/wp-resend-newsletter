<?php
/**
 * Coverage Gate.
 *
 * Reads the Clover report produced by `composer test:coverage` and enforces two rules:
 *
 *   1. Diff coverage — the percentage of NEW/CHANGED executable lines in the current
 *      change set that are covered by tests. This is the hard gate (default 80%).
 *   2. Overall ratchet — total coverage may not drop below the recorded baseline
 *      (.coverage-baseline) by more than the tolerance (default 0.5 points).
 *
 * Diff coverage is the meaningful signal: a legacy project can never pass an absolute
 * overall threshold, but every new line it adds can and should be tested.
 *
 * Usage:
 *   php scripts/coverage-gate.php [options]
 *
 * Options:
 *   --base=<git-ref>      Compare against this ref. Default: HEAD (working tree +
 *                         staged changes); falls back to HEAD~1 when the tree is clean.
 *   --min-diff=<percent>  Diff coverage threshold. Default: 80.
 *   --tolerance=<points>  Allowed overall coverage drop. Default: 0.5.
 *   --clover=<path>       Clover report path. Default: build/coverage/clover.xml.
 *   --baseline=<path>     Baseline file path. Default: .coverage-baseline.
 *   --update-baseline     Write the current overall coverage to the baseline (only on pass).
 *   --no-write            Never touch the baseline file. Use this from read-only contexts
 *                         such as `/review`, which must not create or modify tracked files.
 *   --json                Machine-readable output. Consumers MUST use these numbers verbatim.
 *
 * Exit codes:
 *   0 = pass
 *   1 = fail (below threshold, or overall coverage regressed)
 *   2 = unavailable (no coverage driver / no Clover report) — NOT a pass, NOT a fail.
 */

// Directories whose files are never part of the shipped source, so never gated.
const GATE_EXCLUDED_PREFIXES = array( 'tests/', 'vendor/', 'bin/', 'scripts/', 'node_modules/', 'build/', '.github/' );

$options = gate_parse_args( $argv );
$root    = gate_project_root();

if ( null === $root ) {
	gate_report_unavailable( $options, 'Not inside a git repository — cannot compute diff coverage.' );
}

$cloverPath = gate_absolute_path( $root, $options['clover'] );

if ( ! is_file( $cloverPath ) ) {
	gate_report_unavailable(
		$options,
		sprintf(
			'Clover report not found at %s. Run `composer test:coverage` first; if it printed '
			. '"No code coverage driver available", install PCOV (pecl install pcov) or enable Xdebug.',
			$options['clover']
		)
	);
}

$coverage = gate_parse_clover( $cloverPath, $root );

if ( null === $coverage ) {
	gate_report_unavailable( $options, sprintf( 'Clover report at %s is unreadable or malformed.', $options['clover'] ) );
}

if ( 0 === $coverage['total'] ) {
	gate_report_unavailable(
		$options,
		'Clover report contains no executable lines. Check the <coverage><include> section of phpunit.xml.dist.'
	);
}

$overall = round( $coverage['covered'] / $coverage['total'] * 100, 2 );

// Resolve the comparison base: default to the working tree, fall back to the previous
// commit when nothing is uncommitted (e.g. running the gate right after a commit).
$base = $options['base'];
if ( null === $base ) {
	$base = gate_has_local_changes( $root ) ? 'HEAD' : 'HEAD~1';
	if ( 'HEAD~1' === $base && ! gate_ref_exists( $root, 'HEAD~1' ) ) {
		$base = null; // Initial commit — every tracked file counts as new.
	}
}

$changedLines = gate_collect_changed_lines( $root, $base, $coverage['lines'] );

$diffTotal     = 0;
$diffCovered   = 0;
$uncoveredList = array();

foreach ( $changedLines as $file => $lines ) {
	if ( ! isset( $coverage['lines'][ $file ] ) ) {
		continue; // File is not part of the measured source set.
	}
	$fileUncovered = array();
	foreach ( $lines as $line ) {
		if ( ! isset( $coverage['lines'][ $file ][ $line ] ) ) {
			continue; // Not an executable line (comment, blank, declaration).
		}
		++$diffTotal;
		if ( $coverage['lines'][ $file ][ $line ] > 0 ) {
			++$diffCovered;
		} else {
			$fileUncovered[] = $line;
		}
	}
	if ( ! empty( $fileUncovered ) ) {
		$uncoveredList[ $file ] = gate_compress_ranges( $fileUncovered );
	}
}

$diffCoverage = ( $diffTotal > 0 ) ? round( $diffCovered / $diffTotal * 100, 2 ) : null;

// Baseline handling. A missing baseline is bootstrapped from the current run and never fails.
$baselinePath  = gate_absolute_path( $root, $options['baseline'] );
$baseline      = null;
$baselineFresh = false;
if ( is_file( $baselinePath ) ) {
	$decoded = json_decode( (string) file_get_contents( $baselinePath ), true );
	if ( is_array( $decoded ) && isset( $decoded['overall'] ) && is_numeric( $decoded['overall'] ) ) {
		$baseline = (float) $decoded['overall'];
	}
}
if ( null === $baseline ) {
	// No baseline yet: bootstrap from this run so the ratchet has a floor. In --no-write
	// mode nothing is persisted and the overall check is skipped for this run instead.
	if ( ! $options['no_write'] ) {
		gate_write_baseline( $baselinePath, $overall );
	}
	$baseline      = $overall;
	$baselineFresh = true;
}

$overallDelta = round( $overall - $baseline, 2 );

// Verdict.
$failures = array();
if ( null !== $diffCoverage && $diffCoverage < $options['min_diff'] ) {
	$failures[] = sprintf( 'Diff coverage %.2f%% is below the %.0f%% threshold.', $diffCoverage, $options['min_diff'] );
}
if ( ! $baselineFresh && $overallDelta < -$options['tolerance'] ) {
	$failures[] = sprintf(
		'Overall coverage dropped %.2f points (%.2f%% → %.2f%%), tolerance is %.2f.',
		abs( $overallDelta ),
		$baseline,
		$overall,
		$options['tolerance']
	);
}

$status = empty( $failures ) ? 'pass' : 'fail';

if ( 'pass' === $status && $options['update_baseline'] && ! $options['no_write'] && $overall > $baseline ) {
	gate_write_baseline( $baselinePath, $overall );
}

$result = array(
	'status'         => $status,
	'base'           => ( null === $base ) ? '(initial commit)' : $base,
	'diff_coverage'  => $diffCoverage,
	'diff_covered'   => $diffCovered,
	'diff_total'     => $diffTotal,
	'min_diff'       => $options['min_diff'],
	'overall'        => $overall,
	'overall_lines'  => array( 'covered' => $coverage['covered'], 'total' => $coverage['total'] ),
	'baseline'       => $baseline,
	'baseline_fresh' => $baselineFresh,
	'overall_delta'  => $overallDelta,
	'tolerance'      => $options['tolerance'],
	'uncovered'      => $uncoveredList,
	'failures'       => $failures,
	'reason'         => null,
);

if ( $options['json'] ) {
	echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	exit( 'pass' === $status ? 0 : 1 );
}

gate_print_human( $result );
exit( 'pass' === $status ? 0 : 1 );

// ---------------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------------

/**
 * Parses CLI arguments into an options array.
 *
 * @param array<int, string> $argv Raw argument vector.
 * @return array<string, mixed>
 */
function gate_parse_args( array $argv ) {
	$options = array(
		'base'            => null,
		'min_diff'        => 80.0,
		'tolerance'       => 0.5,
		'clover'          => 'build/coverage/clover.xml',
		'baseline'        => '.coverage-baseline',
		'update_baseline' => false,
		'no_write'        => false,
		'json'            => false,
	);

	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( '--json' === $arg ) {
			$options['json'] = true;
		} elseif ( '--update-baseline' === $arg ) {
			$options['update_baseline'] = true;
		} elseif ( '--no-write' === $arg ) {
			$options['no_write'] = true;
		} elseif ( 0 === strpos( $arg, '--base=' ) ) {
			$options['base'] = substr( $arg, 7 );
		} elseif ( 0 === strpos( $arg, '--min-diff=' ) ) {
			$options['min_diff'] = (float) substr( $arg, 11 );
		} elseif ( 0 === strpos( $arg, '--tolerance=' ) ) {
			$options['tolerance'] = (float) substr( $arg, 12 );
		} elseif ( 0 === strpos( $arg, '--clover=' ) ) {
			$options['clover'] = substr( $arg, 9 );
		} elseif ( 0 === strpos( $arg, '--baseline=' ) ) {
			$options['baseline'] = substr( $arg, 11 );
		}
	}

	return $options;
}

/**
 * Returns the git top-level directory, or null when not in a repository.
 *
 * @return string|null
 */
function gate_project_root() {
	$output = array();
	$code   = 0;
	exec( 'git rev-parse --show-toplevel 2>/dev/null', $output, $code );
	if ( 0 !== $code || empty( $output[0] ) ) {
		return null;
	}
	return rtrim( $output[0], '/' );
}

/**
 * Resolves a possibly relative path against the project root.
 *
 * @param string $root Project root.
 * @param string $path Path from options.
 * @return string
 */
function gate_absolute_path( $root, $path ) {
	if ( '' !== $path && '/' === $path[0] ) {
		return $path;
	}
	return $root . '/' . $path;
}

/**
 * Whether the working tree has uncommitted or untracked changes.
 *
 * @param string $root Project root.
 * @return bool
 */
function gate_has_local_changes( $root ) {
	$output = array();
	$code   = 0;
	exec( sprintf( 'git -C %s status --porcelain 2>/dev/null', escapeshellarg( $root ) ), $output, $code );
	return 0 === $code && ! empty( $output );
}

/**
 * Whether a git ref can be resolved.
 *
 * @param string $root Project root.
 * @param string $ref  Ref to test.
 * @return bool
 */
function gate_ref_exists( $root, $ref ) {
	$output = array();
	$code   = 0;
	exec(
		sprintf( 'git -C %s rev-parse --verify %s 2>/dev/null', escapeshellarg( $root ), escapeshellarg( $ref ) ),
		$output,
		$code
	);
	return 0 === $code;
}

/**
 * Parses a Clover report into per-file executable line hit counts.
 *
 * @param string $path Clover XML path.
 * @param string $root Project root, used to relativize absolute file paths.
 * @return array{lines: array<string, array<int, int>>, covered: int, total: int}|null Null on parse failure.
 */
function gate_parse_clover( $path, $root ) {
	$previous = libxml_use_internal_errors( true );
	$xml      = simplexml_load_file( $path );
	libxml_use_internal_errors( $previous );

	if ( false === $xml ) {
		return null;
	}

	$lines   = array();
	$covered = 0;
	$total   = 0;

	foreach ( $xml->xpath( '//file' ) as $fileNode ) {
		$name = (string) $fileNode['name'];
		if ( '' === $name ) {
			continue;
		}
		$relative = gate_relativize( $name, $root );
		foreach ( $fileNode->line as $lineNode ) {
			if ( 'stmt' !== (string) $lineNode['type'] ) {
				continue; // Only statement lines; method/class rows are summaries.
			}
			$num   = (int) $lineNode['num'];
			$count = (int) $lineNode['count'];
			$lines[ $relative ][ $num ] = $count;
			++$total;
			if ( $count > 0 ) {
				++$covered;
			}
		}
	}

	return array(
		'lines'   => $lines,
		'covered' => $covered,
		'total'   => $total,
	);
}

/**
 * Converts an absolute path to one relative to the project root.
 *
 * @param string $path Absolute or relative path.
 * @param string $root Project root.
 * @return string
 */
function gate_relativize( $path, $root ) {
	$normalized = $path;
	$realRoot   = realpath( $root );
	if ( false !== $realRoot && 0 === strpos( $normalized, $realRoot . '/' ) ) {
		return substr( $normalized, strlen( $realRoot ) + 1 );
	}
	if ( 0 === strpos( $normalized, $root . '/' ) ) {
		return substr( $normalized, strlen( $root ) + 1 );
	}
	return ltrim( $normalized, '/' );
}

/**
 * Collects the added/modified line numbers per PHP file in the change set.
 *
 * Untracked PHP files count in full: every executable line the Clover report knows
 * about is treated as new, otherwise brand-new untested classes would be invisible.
 *
 * @param string                          $root        Project root.
 * @param string|null                     $base        Comparison ref, or null for "everything is new".
 * @param array<string, array<int, int>>  $coverageMap Per-file line map from the Clover report.
 * @return array<string, array<int, int>> file => list of line numbers.
 */
function gate_collect_changed_lines( $root, $base, array $coverageMap ) {
	$changed = array();

	if ( null !== $base ) {
		$command = sprintf(
			'git -C %s diff --unified=0 --no-color --diff-filter=d %s -- "*.php" 2>/dev/null',
			escapeshellarg( $root ),
			escapeshellarg( $base )
		);
		$output  = array();
		$code    = 0;
		exec( $command, $output, $code );

		$currentFile = null;
		foreach ( $output as $line ) {
			if ( 0 === strpos( $line, '+++ ' ) ) {
				$candidate   = substr( $line, 4 );
				$currentFile = ( '/dev/null' === $candidate ) ? null : preg_replace( '#^b/#', '', $candidate );
				continue;
			}
			if ( null === $currentFile || 0 !== strpos( $line, '@@' ) ) {
				continue;
			}
			if ( ! preg_match( '/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $matches ) ) {
				continue;
			}
			$start = (int) $matches[1];
			$count = isset( $matches[2] ) ? (int) $matches[2] : 1;
			for ( $i = 0; $i < $count; $i++ ) {
				$changed[ $currentFile ][] = $start + $i;
			}
		}
	}

	// Untracked files (and, on the initial commit, every tracked file) count in full.
	$statusOutput = array();
	$statusCode   = 0;
	exec(
		sprintf( 'git -C %s status --porcelain --untracked-files=all 2>/dev/null', escapeshellarg( $root ) ),
		$statusOutput,
		$statusCode
	);
	foreach ( $statusOutput as $entry ) {
		if ( 0 !== strpos( $entry, '?? ' ) ) {
			continue;
		}
		$file = trim( substr( $entry, 3 ), '"' );
		if ( '.php' !== substr( $file, -4 ) ) {
			continue;
		}
		if ( isset( $coverageMap[ $file ] ) ) {
			$changed[ $file ] = array_keys( $coverageMap[ $file ] );
		}
	}

	if ( null === $base ) {
		foreach ( $coverageMap as $file => $lineMap ) {
			$changed[ $file ] = array_keys( $lineMap );
		}
	}

	// Drop non-source paths and de-duplicate.
	foreach ( $changed as $file => $lines ) {
		foreach ( GATE_EXCLUDED_PREFIXES as $prefix ) {
			if ( 0 === strpos( $file, $prefix ) ) {
				unset( $changed[ $file ] );
				continue 2;
			}
		}
		$unique = array_values( array_unique( $lines ) );
		sort( $unique );
		$changed[ $file ] = $unique;
	}

	return $changed;
}

/**
 * Compresses a sorted list of line numbers into "12-15, 20" style ranges.
 *
 * @param array<int, int> $lines Line numbers.
 * @return string
 */
function gate_compress_ranges( array $lines ) {
	sort( $lines );
	$ranges = array();
	$start  = null;
	$prev   = null;

	foreach ( $lines as $line ) {
		if ( null === $start ) {
			$start = $line;
			$prev  = $line;
			continue;
		}
		if ( $line === $prev + 1 ) {
			$prev = $line;
			continue;
		}
		$ranges[] = ( $start === $prev ) ? (string) $start : $start . '-' . $prev;
		$start    = $line;
		$prev     = $line;
	}
	if ( null !== $start ) {
		$ranges[] = ( $start === $prev ) ? (string) $start : $start . '-' . $prev;
	}

	return implode( ', ', $ranges );
}

/**
 * Persists the overall coverage baseline.
 *
 * @param string $path    Baseline file path.
 * @param float  $overall Overall coverage percentage.
 * @return void
 */
function gate_write_baseline( $path, $overall ) {
	$payload = array(
		'overall'    => $overall,
		'updated_at' => gmdate( 'c' ),
		'note'       => 'Overall coverage ratchet. Commit this file so CI and teammates share the same floor.',
	);
	file_put_contents( $path, json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
}

/**
 * Emits the "cannot measure" result and exits with code 2.
 *
 * @param array<string, mixed> $options Parsed options.
 * @param string               $reason  Human-readable reason.
 * @return void
 */
function gate_report_unavailable( array $options, $reason ) {
	if ( $options['json'] ) {
		echo json_encode(
			array(
				'status'        => 'unavailable',
				'diff_coverage' => null,
				'overall'       => null,
				'reason'        => $reason,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n";
	} else {
		fwrite( STDERR, "⚪ Coverage unavailable\n   " . $reason . "\n" );
	}
	exit( 2 );
}

/**
 * Prints the human-readable gate report.
 *
 * @param array<string, mixed> $result Result payload.
 * @return void
 */
function gate_print_human( array $result ) {
	echo "\n=== Coverage Gate ===\n";
	echo 'Base            : ' . $result['base'] . "\n";

	if ( null === $result['diff_coverage'] ) {
		echo "Diff coverage   : N/A (no new executable lines in this change set)\n";
	} else {
		printf(
			"Diff coverage   : %.2f%% (%d/%d new lines covered) — threshold %.0f%% %s\n",
			$result['diff_coverage'],
			$result['diff_covered'],
			$result['diff_total'],
			$result['min_diff'],
			( $result['diff_coverage'] >= $result['min_diff'] ) ? '✅' : '❌'
		);
	}

	printf(
		"Overall coverage: %.2f%% (%d/%d lines)\n",
		$result['overall'],
		$result['overall_lines']['covered'],
		$result['overall_lines']['total']
	);

	if ( $result['baseline_fresh'] ) {
		printf( "Baseline        : none yet — recorded %.2f%% as the floor\n", $result['baseline'] );
	} else {
		printf(
			"Baseline        : %.2f%% (delta %+.2f, tolerance %.2f) %s\n",
			$result['baseline'],
			$result['overall_delta'],
			$result['tolerance'],
			( $result['overall_delta'] >= -$result['tolerance'] ) ? '✅' : '❌'
		);
	}

	if ( ! empty( $result['uncovered'] ) ) {
		echo "\nUncovered new lines:\n";
		foreach ( $result['uncovered'] as $file => $ranges ) {
			echo '  ' . $file . ':' . $ranges . "\n";
		}
	}

	echo "\n";
	if ( 'pass' === $result['status'] ) {
		echo "✅ Coverage gate passed\n";
	} else {
		echo "❌ Coverage gate failed\n";
		foreach ( $result['failures'] as $failure ) {
			echo '   - ' . $failure . "\n";
		}
	}
	echo "\n";
}
