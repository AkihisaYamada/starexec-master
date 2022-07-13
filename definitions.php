<?php

set_time_limit(300);

	function seconds2str($s) {
		$d = floor($s/(24*60*60));
		$s = $s%(24*60*60);
		$h = floor($s/(60*60));
		$s = $s%(60*60);
		$m = floor($s/60);
		$s = $s%60;
		return ($d>0? $d .'d ' : '').sprintf("%'02d:%'02d:%'02d",$h,$m,$s);
	}

	function str2str($str) {// escape single quotes
		return "'" . str_replace( ['\\', '\''], ['\\\\','\\\''], $str ) . "'";
	}

	function cachezip($remote,$local,$refresh) {
		if( file_exists($local) && ( !$refresh || filemtime($local) + 5 > time() ) ) {
			return;
		}
		$tmpzip=tempnam("./fromStarExec","");
		if( !copy($remote,$tmpzip) ) {
			print_r(error_get_last()["message"]);
			return;
		}
		exec( "unzip -o $tmpzip -d fromStarExec", $out, $ret );
		if( $ret ) {
			exit("failed to unzip job info; exit code: $ret\n".$out);
		}
		unlink($tmpzip);
		exec( "./fix-starexec-csv.sh '$local'" );
		chmod( $local, 0766 );
	}
	function jobid2csv($jobid) {
		return "fromStarExec/Job$jobid/Job" . $jobid . "_info.csv";
	}
	function row2record($header,$row) {
		if( is_null($row[0]) ) {
			return null;
		}
		$record = [];
		foreach( $header as $i => $field ) {
			$record[$field] = $row[$i];
		}
		return $record;
	}
	$scored_keys = [
		'CERTIFIED YES' => ['text' => 'YES', 'scored' => true],
		'CERTIFIED NO' => ['text' => 'NO', 'scored' => true],
		'CERTIFIED UP' => ['text' => 'UP', 'scored' => true],
		'CERTIFIED LOW' => ['text' => 'LOW', 'scored' => true],
		'YES' => ['scored' => true],
		'NO' => ['scored' => true],
		'UP' => ['scored' => true],
		'LOW' => ['scored' => true],
		'togo' => ['scored' => false],
		'MAYBE' => ['scored' => false],
		'timeout' => ['scored' => false],
		'memout' => ['scored' => false],
		'error' => ['scored' => false],
	];
	function new_scores() {
		return [
			'score' => 0,
			'miss' => 0,
			'scorestogo' => 0,
			'conflicts' => 0,
			'done' => 0,
			'togo' => 0,
			'cpu' => 0,
			'time' => 0,
			'certtime' => 0,
		];
	}
	function parse_record(&$record,$bm_dir,$len,&$results) {
		if( $record != null ) {
			$bm_raw = $record['benchmark'];
			unset($record['benchmark']);
			$bm_name = substr( $bm_raw, strpos($bm_raw,$bm_dir) + $len);
			$here = &$results[$bm_name];
			$here['participants'][$record['configuration id']] = $record;
		}
	};
	function parse_results($csv, $bm_dir, &$results, &$participants, $layer) {
		global $scored_keys;
		$len = strlen($bm_dir);
		$file = new SplFileObject($csv);
		$file->setFlags( SplFileObject::READ_CSV );
		$header = $file->current();
		$file->next();
		// first tool
		$record = row2record($header,$file->current());
		$file->next();
		parse_record($record,$bm_dir,$len,$results);
		$configid = $record['configuration id'];
		$first = $configid;
		// first loop
		for(;;) {
			$participants[$configid] = array_merge( new_scores(), [
				'layer' => $layer,
				'solver' => $record['solver'],
				'solver id' => $record['solver id'],
				'configuration' => $record['configuration'],
			]);
			foreach( $scored_keys as $key => $val ) {
				$participants[$configid][$key] = 0;
			}
			$record = row2record($header,$file->current());
			$file->next();
			parse_record($record,$bm_dir,$len,$results);
			$configid = $record['configuration id'];
			if( $configid == $first ) {
				break;
			}
		}
		// remaining
		for(; !$file->eof(); $file->next() ) {
			parse_record(row2record($header,$file->current()),$bm_dir,$len,$results);
		}
	}
	function jobid2remote($jobid) {
		return "https://www.starexec.org/starexec/secure/download?type=job&id=$jobid&returnids=true&getcompleted=false";
	}
	function escape_filename($name) {
		return preg_replace('/[: \\/\\\\]/','_',$name);
	}
	function jobname2local($competition,$jobname) {
		return $competition.'/'.escape_filename($jobname).'.html';
	}
	function jobname2graph($competition,$jobname) {
		return $competition.'/'.escape_filename($jobname).'.graph.html';
	}
	function jobname2sumfile($competition,$jobname) {
		return $competition.'/'.escape_filename($jobname).'.json';
	}
	function id2sumfile($competition,$id) {
		return $competition.'/Job'.$id.'.json';
	}
	function jobname2vbsfile($competition,$jobname) {
		return $competition.'/'.escape_filename($jobname).'.VBS.json';
	}
	function spaceid2url($id) {
		return 'https://www.starexec.org/starexec/secure/explore/spaces.jsp?id='.$id;
	}
	function pairid2url($pairid) {
		return "https://www.starexec.org/starexec/secure/details/pair.jsp?id=$pairid";
	}
	function pairid2outurl($pairid) {
		return 'https://www.starexec.org/starexec/services/jobs/pairs/'. $pairid .'/stdout/1?limit=-1';
	}
// For complexity
	function bound2str( $bound ) {
		switch( $bound ) {
			case 0: return '1';
			case 1: return 'n';
			case 999: return 'n<sup>?</sup>';
			case 1000: return 'NonPoly';
			case 1001: return '&omega;';
			default: return 'n<sup>'.$bound.'</sup>';
		}
	}
	function str2lower( $string ) {
		if( $string == 'Omega(1)' ) {
			return 0;
		} else if( preg_match( '/Omega\\(n\\^([0-9]+)\\)/', $string, $matches ) ) {
			return $matches[1];
		} else if( $string == 'NON_POLY' ) {
			return 1000;
		} else {
			return 0;
		}
	}
	function str2upper( $string ) {
		if( $string == 'O(1)' ) {
			return 0;
		} else if( preg_match( '/O\\(n\\^([0-9]+)\\)/', $string, $matches ) ) {
			return $matches[1];
		} else if( $string == 'POLY' ) {
			return 999;
		} else {
			return 1001;
		}
	}
	function error_claim() {
		return ['error' => 1];
	}
	function timeout_claim() {
		return ['timeout' => 1];
	}
	function memout_claim() {
		return ['memout' => 1];
	}
	function str2claim($str) {
		if( $str == 'YES' || $str == 'NO' ) {
			return [ $str => 1 ];
		}
		if( preg_match( '/WORST_CASE\\(\\s*(.+)\\s*,\\s*(.+)\\s*\\)/', $str, $matches ) ) {
			$ret = [];
			$low = str2lower($matches[1]);
			$up = str2upper($matches[2]);
			$set = false;
			if( $low > 0 ) {
				$ret['LOW'] = $low;
				$set = true;
			}
			if( $up < 1001 ) {
				$ret['YES'] = 1;
				$ret['UP'] = $up;
				$set = true;
			}
			if( $set ) {
				return $ret;
			}
		}
		return [ 'MAYBE' => 1 ];
	}
	function init_claim_set(&$claims) {
		$claims['YES'] = 0;
		$claims['NO'] = 0;
		$claims['UP'] = [];
		$claims['LOW'] = [];
		$claims['miss'] = 0;
		$claims['togo'] = 0;
	}
	function add_claim(&$claims,$claim) {
		foreach( ['MAYBE','timeout','memout','error'] as $key ) {
			if( array_key_exists($key,$claim) ) {
				$claims['miss']++;
				return;
			}
		}
		foreach( ['YES','NO'] as $key ) {
			if( array_key_exists($key,$claim) ) {
				$claims[$key]++;
			}
		}
		foreach( ['UP','LOW'] as $key ) {
			if( array_key_exists($key,$claim) ) {
				array_push($claims[$key],$claim[$key]);
			}
		}
	}
	function add_claim_togo(&$claims) {
			$claims['togo']++;
	}
	function claim2scores($claim,$cert,$max_score) {
		$ret = ['score' => 0, 'miss' => $max_score ];
		if( $cert == 'REJECTED' || $cert == 'UNSUPPORTED' ) {
			return $ret;
		}
		$pre = $cert == 'CERTIFIED' ? $cert.' ' : '';
		if( array_key_exists('NO',$claim) ) {
			$ret['score']++;
			$ret['miss']--;
			$ret[$pre.'NO'] = 1;
		} else if( array_key_exists('LOW',$claim) ) {
			$low = $claim['LOW'];
			$lowscore = $low == 1000 ? 1.0 : 1.0 - .5**$low;
			$ret['score'] += $lowscore;
			$ret['miss'] -= $lowscore;
			$ret[$pre.'LOW'] = $lowscore;
		}
		if( array_key_exists('UP',$claim) ) {
			$upscore = 1 + .5**$claim['UP'];
			$ret['score'] += $upscore;
			$ret['miss'] -= $upscore;
			$ret[$pre.'UP'] = $upscore;
		} else if( array_key_exists('YES',$claim) ) {
			$ret['score']++;
			$ret['miss']--;
			$ret[$pre.'YES'] = 1;
		}
		if( array_key_exists('MAYBE',$claim) ) {
			$ret['MAYBE'] = 1;
		}
		if( array_key_exists('timeout',$claim) ) {
			$ret['timeout'] = 1;
		}
		if( array_key_exists('memout',$claim) ) {
			$ret['memout'] = 1;
		}
		if( array_key_exists('error',$claim) ) {
			$ret['error'] = 1;
		}
		return $ret;
	}
	function claim2str($claim) {
		if( array_key_exists('NO',$claim) ) {
			return 'NO';
		}
		if( array_key_exists('UP',$claim) ) {
			$up = $claim['UP'];
			$low = array_key_exists('LOW',$claim) ? $claim['LOW'] : 0;
			if( $low == $up ) {
				return '&Theta;('.bound2str($up).')';
			}
			return ($low == 0 ? '' : '&Omega;('.bound2str($low).')').'―'.($up == 999 ? 'POLY' : 'O('.bound2str($up).')');
		}
		if( array_key_exists('LOW',$claim) ) {
			$low = $claim['LOW'];
			if( $low == 1000 ) {
				return 'NON_POLY';
			}
			return '&Omega;('.bound2str($low).')―';
		}
		if( array_key_exists('YES',$claim) ) {
			return 'YES';
		}
		return 'MAYBE';
	}
	function cert2str($cert) {
		switch($cert) {
			case 'CERTIFIED':	return '✔';
			default: return $cert;
		}
	}
	function claim2class($claim,$cert) {
		switch($cert) {
			case 'certification timeout': $pre = 'certout '; break;
			case 'CERTIFIED': case 'REJECTED': case 'UNSUPPORTED': $pre = $cert . ' '; break;
			default: $pre = ''; break;
		}
		if( array_key_exists('NO',$claim) ) {
			return $pre.'NO';
		}
		if( array_key_exists('UP',$claim) ) {
			$diff = $claim['UP'] - (array_key_exists('LOW',$claim) ? $claim['LOW'] : 0);
			return $pre.'UP d'.($diff < 3 ? $diff : '3');
		}
		if( array_key_exists('LOW',$claim) ) {
			$low = $claim['LOW'];
			return $pre.'LOW '.( $low == 1000 ? 'np' : 'd'.($low < 3 ? $low : '3') );
		}
		if(	array_key_exists('YES',$claim) ) {
			return $pre.'YES';
		}
		if( array_key_exists('error',$claim) ) {
			return 'error';
		}
		if( array_key_exists('timeout',$claim) ) {
			return 'timeout';
		}
		if( array_key_exists('memout',$claim) ) {
			return 'memout';
		}
		return 'MAYBE';
	}
	function status2complete($status) {
		return $status == 'complete';
	}
	function status2pending($status) {
		return substr($status,0,7) == 'pending';
	}
	function status2enqueued($status) {
		return $status == 'enqueued';
	}
	function status2incomplete($status) {
		return $status == 'incomplete';
	}
	function status2paused($status) {
		return $status == 'paused';
	}
	function status2finished($status) {
		return !status2pending($status) && !status2enqueued($status) && !status2incomplete($status) && !status2paused($status);
	}
	function status2error($status) {
		return strstr($status, 'error') != false;
	}
	function status2timeout($status) {
		return substr($status,0,7) == 'timeout';
	}
	function status2memout($status) {
		return $status == 'memout';
	}
	function status2class($status) {
		if( status2complete($status) ) {
			return 'complete';
		} else if( status2incomplete($status) ) {
			return 'incomplete';
		} else if( status2timeout($status) ) {
			return 'timeout';
		} else if( status2memout($status) ) {
			return 'memout';
		} else if( status2enqueued($status) ) {
			return 'enqueued';
		} else {
			return 'error';
		}
	}
	function format_bm( $string ) {
		$ret = str_replace( '/', '/<wbr>',$string );
		$ret = str_replace( '_', '_<wbr>',$ret );
		return $ret;
	}
	function parse_time( $string ) {
		preg_match( '/([0-9]+\\.[0-9]?[0-9]?).*/', $string, $matches );
		return $matches[1];
	}
	function jobid2url($jobid) {
		return "https://www.starexec.org/starexec/secure/details/job.jsp?id=$jobid";
	}
	function bm2url($bm,$bmid,$db,$bm_prefix) {
		if( preg_match( '/TPDB(\\s*([0-9.]+))?/', $db, $matches ) == 1 ) {
			return 'https://termcomp.github.io/tpdb.html?'.($matches[1]=='' ? '' : 'ver='.$matches[2].'&').'path='.urlencode($bm_prefix.$bm);
		}
		if( preg_match( '/COPS/', $db ) == 1 ) {
			preg_match( '/(.*)\.trs/', $bm, $matches );
			return 'http://cops.uibk.ac.at/?q='.$matches[1];
		}
		return 'https://www.starexec.org/starexec/services/benchmarks/'. $bmid .'/contents?limit=-1';
	}
	function bmid2remote($bmid) {
		return "https://www.starexec.org/starexec/secure/details/benchmark.jsp?id=$bmid";
	}
	function solverid2url($solverid) {
		return "https://www.starexec.org/starexec/secure/details/solver.jsp?id=$solverid";
	}
	function configid2url($configid) {
		return "https://www.starexec.org/starexec/secure/details/configuration.jsp?id=$configid";
	}
	function claims2description($claims) {
		$best = [];
		$YES = $claims['YES'];
		if( $YES > 0 ) {
			$best['YES'] = 1;
		}
		$NO = $claims['NO'];
		if( $NO > 0 ) {
			$best['NO'] = 1;
		}
		$UP = $claims['UP'];
		$LOW = $claims['LOW'];
		$MAYBE = $claims['miss'];
		$togo = $claims['togo'];
		if( empty($UP) ) {
			$nUP = 0;
			$vUP = 0;
		} else {
			$minUP = min($UP);
			$best['UP'] = $minUP;
			$nUP = count(array_filter($UP,function($up){return $up == $minUP;}));
			$vUP = count(array_unique($UP));// number of different UP claims
		}
		if( empty($LOW) ) {
			$maxLOW = 0;
			$nLOW = 0;
			$vLOW = 0;
		} else {
			$maxLOW = max($LOW);
			$best['LOW'] = $maxLOW;
			$nLOW = count(array_filter($LOW,function($low){return $low == $maxLOW;}));
			$vLOW = count(array_unique($LOW));
		}
		$begun = !($YES == 0 && $NO == 0 && $MAYBE == 0 && empty($UP) && empty($LOW));
		$conflicting = ( $YES > 0 && $NO > 0 ) || isset($minUP) && $minUP < $maxLOW;
		$interesting = ( ( $YES > 0 || $NO > 0 ) && $MAYBE > 0) || $vUP > 1 || $vLOW > 1;
		$finished = $togo == 0;
		$unsolved = $finished && $YES == 0 && $NO == 0 && $nUP == 0 && $nLOW == 0;
		$solo = $finished && ($YES == 1 || $NO == 1 || $nUP == 1 || $nLOW == 1);
		return [
			'vbs' => $best,
			'begun' => $begun,
			'conflicting' => $conflicting,
			'interesting' => $interesting,
			'finished' => $finished,
			'solo' => $solo,
			'unsolved' => $unsolved,
			'key' => ($begun ? 'b':'').($conflicting ? 'c':'').($interesting ? 'i' : '').($solo ? 's' : '').($unsolved ? 'u' : '').($finished ? 'f':''),
		];
	}
	// Making certified and demonstration categories
	function make_categories($raw_mcats,$closed) {
		$demos = [];
		$mcats = [];
		foreach( $raw_mcats as $mcat_name => $raw_cats ) {
			$cats = [];
			foreach( $raw_cats as $cat_name => $cat ) {
				$certinfo = $cat['certified'];
				if( $cat['id'] != null ) {
					if( array_key_exists('id',$certinfo) && $certinfo['id'] != null ) {
						$cat['id'] .= '_'.$certinfo['id'];
					}
				}
				$cats[$cat_name] = $cat;
				if( $closed && array_key_exists('participants',$cat) ) {
					$cnt = count($cat['participants']) + count($certinfo['participants']);
					if( $cnt == 0 && $cat['id'] != null ) {
						unset($cats[$cat_name]);// remove unparticipated category
					} else if( $cnt == 1 ) {
						$demos[$cat_name] = $cat;
						unset($cats[$cat_name]);
					}
				}
			}
			$mcats[$mcat_name] = $cats;
		}
		if( $demos != [] ) {
			$mcats['Demonstrations'] = $demos;
		}
		return $mcats;
	}
?>
<script src="definitions.js"></script>
