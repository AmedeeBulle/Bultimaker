<?php
//
//  Bul(le)timaker
//
//  Build Ultimaker firmware
//  Server side in PHP
//

// Site config
require_once 'bultimaker.inc';

// Constants
define("GIT"            , "git --git-dir=".MARLIN."/.git");   // Git command
define("BUILD_PREFIX"   , "Marlin_");                         // Prefix for build dir
define("FIRMWARE"       , "Marlin.hex");                      // Firmware file name

// Simple logging
ini_set('log_errors', 1);
function logger($data) {
  // Add some info
  $log = array(
    'date' => date("Y-m-d h:m:s"),
	'file' => __FILE__,
	'remote_addr' => $_SERVER['REMOTE_ADDR'],
	'user_agent' => $_SERVER['HTTP_USER_AGENT'],
	'data' => $data
  );
  // Convert to string and send to log file
  $json_data = json_encode($log) . PHP_EOL;
  error_log($json_data, 3, LOG_FILE);
}

// We always answer in JSON
header('Content-Type: application/json');

// Response array
$resp = array(
  'status' => -1, 
  'log' => 'Invalid request', 
);

// Decode json input as array
$data = json_decode(file_get_contents('php://input'), true);

if ( !isset($data) || json_last_error() != JSON_ERROR_NONE )
  goto exit_json;  // Invalid JSON

// Sanitize input
foreach ($data as $key => $value) {
  $data[$key] = preg_replace('/[^a-z0-9-_.+:]/i', '', $value);
}

if ( !isset($data['cmd']))
  goto exit_json;  // No request

switch ($data['cmd']) {
  case "info":
    // Returns info on the git repo
    $resp['url']          = exec(GIT.' config --get remote.origin.url');
    $resp['branch']       = exec(GIT.' rev-parse --abbrev-ref HEAD');
    $resp['hash']         = exec(GIT.' log -1  --format="%H"');
    $resp['shortHash']    = exec(GIT.' log -1  --format="%h"');
    $resp['tag']          = exec(GIT.' describe --tags --abbrev=0');
    $resp['authorDate']   = exec(GIT.' log -1  --format="%ar"');
    $resp['subject']      = shell_exec(GIT.' log -1  --format="%s"');
    $resp['experimental'] = strpos($resp['branch'], 'Experimental') === FALSE ? 0 : 1;
    $resp['log']          = '';
    $resp['status']       = 0;
    break;
  case "build":
    // build the firmware
    // We have currently the following parameters:
    //  target, motherboard, extruders, temp0, temp1, tempBed, baudrate, controller, language
  
    // Create unique directory for the build
    $buildDir = sys_get_temp_dir() . '/' . uniqid(BUILD_PREFIX);
    if(! mkdir($buildDir) ) {
      $resp['log'] = 'Cannot create build directory';
      goto exit_json;
    }
  
    // Generate the command line

    // Generic make parameters
    $cmd = 'make --no-print-directory ' .
      '-C "'                  . MARLIN                . '/Marlin" ' .
      'ARDUINO_INSTALL_DIR="' . ARDUINO_PATH          . '" ' .
      'ARDUINO_VERSION="'     . ARDUINO_VERSION       . '" ' .
      'AVR_TOOLS_PATH="'      . ARDUINO_PATH          . '/hardware/tools/avr/bin/" ' .
      'BUILD_DIR="'           . $buildDir             . '" ' .
      'HARDWARE_MOTHERBOARD=' . $data['motherboard']  . ' ';
    // Add UG8Lib for the Full Graphic
    if ($data['controller'] == 'FGSC') {
      $cmd .= 'U8GLIB=1 ';
    }

    // Defines
    $cmd .= 'DEFINES="'.
      'VERSION_BASE=\'\\"'    . ($data['motherboard'] == 7 ? 'Ultimaker:' : 'Ultimaker+:') . 
                                exec(GIT.' describe --tags --abbrev=0 | sed -e "s!UMOU_!!"') . '\\"\'' .
      ' VERSION_PROFILE=\'\\"'. $data['baudrate'] . ($data['extruders'] == 1 ? '_single' : '_dual') . '\\"\'' .
      ' BAUDRATE='            . $data['baudrate'] . 
      ' EXTRUDERS='           . $data['extruders'] . 
      ' TEMP_SENSOR_BED='     . $data['tempBed'] . 
      ' TEMP_SENSOR_0='       . $data['temp0'] .
      ' TEMP_SENSOR_1='       . ($data['extruders'] == 1 ? '0' : $data['temp1']) .
      ' HEATER_0_MAXTEMP='    . $data['maxtemp0'] .
      ' HEATER_1_MAXTEMP='    . $data['maxtemp1'] .
      ' INVERT_X_DIR='        . ($data['invertX'] == 0 ? 'false' : 'true') .
      ' INVERT_Y_DIR='        . ($data['invertY'] == 0 ? 'false' : 'true') .
      ' INVERT_Z_DIR='        . ($data['invertZ'] == 0 ? 'false' : 'true') .
      ' INVERT_E0_DIR='       . ($data['invertE0'] == 0 ? 'false' : 'true') .
      ' INVERT_E1_DIR='       . ($data['invertE1'] == 0 ? 'false' : 'true') .
      ' LANGUAGE_CHOICE='     . $data['language']
      ;
    if ($data['target'] == 'HBK') {
      $cmd .= ' ULTIMAKER_HBK';
    }
    if ($data['controller'] == 'FGSC') {
      $cmd .= ' REPRAP_DISCOUNT_FULL_GRAPHIC_SMART_CONTROLLER NO_ULTIMAKERCONROLLER';
    }
    if ($data['timeout'] != 15000) {
      $cmd .= ' LCD_TIMEOUT_TO_STATUS=' . $data['timeout'];
    }
    if ($data['displayFan'] == 1) {
      $cmd .= ' DISPLAY_FAN';
    }
    if ($data['pidBed'] == 1) {
      $cmd .= ' PIDTEMPBED';
    }
    if ($data['actionCommand'] == 1) {
      $cmd .= ' ACTION_COMMAND';
    }
    if ($data['pulleys'] == 1) {
      $cmd .= ' ULTIMAKER_GT2';
    }
    if ($data['beep'] == 'Marlin') {
      $cmd .= ' LCD_FEEDBACK_FREQUENCY_HZ=5000 LCD_FEEDBACK_FREQUENCY_DURATION_MS=2';
    }
    if ($data['reverseEncoder'] == 1) {
      $cmd .= ' REVERSE_ENCODER';
    }
    if ($data['filRunoutSensor'] == 1) {
      $cmd .= ' FILAMENT_RUNOUT_SENSOR FILRUNOUT_PIN=' . $data['frsPin'];
    if ($data['frsInvert'] == 1) {
        $cmd .= ' FIL_RUNOUT_INVERT';
      }
    if ($data['frsPullup'] == 1) {
        $cmd .= ' ENDSTOPPULLUP_FIL_RUNOUT';
      }
    }
    if ($data['fanKick'] > 0) {
      $cmd .= ' FAN_KICKSTART_TIME=' . $data['fanKick'];
    }
    if ($data['fanMinPwm'] > 0) {
      $cmd .= ' FAN_MIN_PWM=' . $data['fanMinPwm'];
    }
    if ($data['fanSlowPwm'] == 1) {
      $cmd .= ' SLOW_PWM_FAN';
    }
    if ($data['e0AutoFan'] == 1) {
      $cmd .= ' EXTRUDER_0_AUTO_FAN_PIN=255';
    }
    if ($data['e1AutoFan'] == 1) {
      $cmd .= ' EXTRUDER_1_AUTO_FAN_PIN=255';
    }
    // Close defines
    $cmd .= '" ';

    // Capture errors and don't leak unnecessary server info
    $cmd .= '2>&1 | sed -e "s!' . ARDUINO_PATH . '!ARDUINO_PATH!g" -e "s!' . $buildDir . '!BUILD_DIR!g" ';

    // Build the firmware!
    $resp['log'] = shell_exec($cmd);
    // $resp['log'] .= "\n" . $cmd;

    $firmware = $buildDir . '/' . FIRMWARE;
  
    // Ensure we have something to return
    if (!file_exists($firmware)) {
      exec('rm -rf "' . $buildDir . '"');
      goto exit_json;
    }

    // Load file and compute MD5 hash
    $resp['firmware'] = file_get_contents($firmware);
    $resp['md5'] = md5($resp['firmware']);

    // Cleanup
    exec('rm -rf "' . $buildDir . '"');
  
    // Call it a success and log!
    $resp['status']     = 0;
	$log['request'] = $data;
	$log['command'] = $cmd;
	$log['md5'] = $resp['md5'];
	logger($log);
    break;
  default:
    // Unknown request
    goto exit_json;
}
    
exit_json:

echo json_encode($resp);

?>
