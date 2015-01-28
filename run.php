#!/usr/bin/php
<?php

/**
 * PHP client for Rachio Irrigation controller
 *
 * The Rachio irrigation controller has great hardware.  The Android and web
 * apps are also pretty decent.  However, in my personal opinion, their watering
 * model hasn't worked out that great for me: your mileage may vary.  Gratefully,
 * they have provided API access that allows for custom watering models such as
 * this one.
 *
 * Includes a custom tweakable temperature based watering model
 *
 * LICENSE: The MIT License (MIT)
 * 
 * Copyright (c) 2015 Waheed Ayubi
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @author Waheed Ayubi <wayubi@gmail.com>
 * @copyright 2015 Waheed Ayubi
 * @license http://opensource.org/licenses/MIT MIT License (MIT)
 * @link http://www.github.com/asdf
 * 
 */

namespace W;

class Model
{
	private static $rain_check_days = 2; // max 10
	private static $debug = true;

	private static $calendar = array(
		1  => array( 'name' => 'January',   'temperature_basis' => 'Low',  'multiplier' => 1.0 ),
		2  => array( 'name' => 'February',  'temperature_basis' => 'Low',  'multiplier' => 1.0 ),
		3  => array( 'name' => 'March',     'temperature_basis' => 'Avg',  'multiplier' => 1.0 ),
		4  => array( 'name' => 'April',     'temperature_basis' => 'Avg',  'multiplier' => 1.0 ),
		5  => array( 'name' => 'May',       'temperature_basis' => 'Avg',  'multiplier' => 1.0 ),
		6  => array( 'name' => 'June',      'temperature_basis' => 'High', 'multiplier' => 1.0 ),
		7  => array( 'name' => 'July',      'temperature_basis' => 'High', 'multiplier' => 1.0 ),
		8  => array( 'name' => 'August',    'temperature_basis' => 'High', 'multiplier' => 1.0 ),
		9  => array( 'name' => 'September', 'temperature_basis' => 'Avg',  'multiplier' => 1.0 ),
		10 => array( 'name' => 'October',   'temperature_basis' => 'Avg',  'multiplier' => 1.0 ),
		11 => array( 'name' => 'November',  'temperature_basis' => 'Avg',  'multiplier' => 1.0 ),
		12 => array( 'name' => 'December',  'temperature_basis' => 'Low',  'multiplier' => 1.0 ),
	);

	public static function getRainCheckDays()
	{
		return (int) static::$rain_check_days;
	}

	public static function getTemperatureBasis($timezone)
	{
		date_default_timezone_set($timezone);
		return static::$calendar[date('n')]['temperature_basis'];
	}

	public static function getAdjustedRuntime($basis, $temperature)
	{
		if (static::$debug) return 1;

		$multiplier  = (float) static::$calendar[date('n')]['multiplier'];
		return (int) ( $basis * $temperature * .01 * $multiplier );
	}
}

class Rachio
{
	private static $api_token = '==== ENTER YOUR RACHIO API KEY HERE ====';
	private static $rain_delay_days = 7; // max 7

	private static $zones = array(
		1 => array( 'name' => 'Zone 1 - Parkway',       'runtime_basis' => 8),
		2 => array( 'name' => 'Zone 2 - Front Yard',    'runtime_basis' => 22),
		3 => array( 'name' => 'Zone 3 - Office Garden', 'runtime_basis' => 0),
		4 => array( 'name' => 'Zone 4 - Back Yard',     'runtime_basis' => 22),
		5 => array( 'name' => 'Zone 5 - Fruit Trees',   'runtime_basis' => 98),
		6 => array( 'name' => 'Zone 6',                 'runtime_basis' => 0),
		7 => array( 'name' => 'Zone 7',                 'runtime_basis' => 0),
		8 => array( 'name' => 'Zone 8',                 'runtime_basis' => 0)
	);

	public static function run()
	{
		$api_token = static::$api_token;

		$result = Curl::request('https://api.rach.io/1/public/person/info', array(
			'Content-Type: application/json',
			"Authorization: Bearer ${api_token}"
		));

		$person_id = $result->id;

		$result = Curl::request("https://api.rach.io/1/public/person/${person_id}", array(
			'Content-Type: application/json',
			"Authorization: Bearer ${api_token}"
		));

		$device_id = (string) $result->devices[0]->id;

		$rain_delay_start = (int) $result->devices[0]->rainDelayStartDate;
		$rain_delay_expiration = (int) $result->devices[0]->rainDelayExpirationDate;
		$rain_delay_active = (boolean) ($rain_delay_expiration >= $rain_delay_start) ? true : false;

		$timezone = (string) $result->devices[0]->timeZone;
		$zip      = (string) $result->devices[0]->zip;
		$zones    = (array) $result->devices[0]->zones;

		Wunderground::request($zip, $timezone);

		$rain_delay_wunder_active = (boolean) Wunderground::rainForecast();
		if ($rain_delay_wunder_active) {
			$json = json_encode(array(
				'id' => $device_id,
				'duration' => static::$rain_delay_days * 86400
			));

			$result = Curl::request("https://api.rach.io/1/public/device/rain_delay", array(
				'Content-Type: application/json',
				"Authorization: Bearer ${api_token}"
			), 'PUT', $json);

			$rain_delay_active = true;
		}

		if ($rain_delay_active) {
			echo '=== Stopping: Rain Delay ===' . PHP_EOL;
			return;
		}

		$temperature = (int) Wunderground::getTemperature((string) Model::getTemperatureBasis($timezone));

		$start_zones = array();
		foreach ($zones as $zone)
		{
			$id         = (string) $zone->id;
			$zoneNumber = (int) $zone->zoneNumber;
			$name       = (string) $zone->name;
			$enabled    = (boolean) $zone->enabled;

			if (!$enabled) continue;

			$runtime_basis = (int) static::$zones[$zoneNumber]['runtime_basis'];
			$runtime_adjusted = (int) Model::getAdjustedRuntime($runtime_basis, $temperature);

			$start_zones[] = array(
				'id'        => $id,
				'duration'  => $runtime_adjusted * 60
			);
		}

		$json = json_encode(array('zones' => $start_zones));

		$result = Curl::request("https://api.rach.io/1/public/zone/start_multiple", array(
			'Content-Type: application/json',
			"Authorization: Bearer ${api_token}"
		), 'PUT', $json);

		echo "=== Done: Lawn Watered ===" . PHP_EOL;
	}
}

class Wunderground
{
	private static $api_token = '==== SIGN UP FOR A FREE API TOKEN AT WUNDERGROUND.COM ====';

	private static $data = array();

	public static function request($zip, $timezone)
	{
		date_default_timezone_set($timezone);

		$api_token = static::$api_token;

		$result = Curl::request("http://api.wunderground.com/api/${api_token}/forecast10day/q/${zip}.json");
		$forecastdays = $result->forecast->simpleforecast->forecastday;

		$data = array();

		foreach ($forecastdays as $forecastday) {
			$date = (string) date('Y-m-d', $forecastday->date->epoch);
			$high = (int) $forecastday->high->fahrenheit;
			$low  = (int) $forecastday->low->fahrenheit;
			$rain = (boolean) $forecastday->qpf_allday->in;

			$data[$date] = array(
				'high' => (int) $high,
				'low'  => (int) $low,
				'avg'  => (int) (($high + $low) / 2),
				'rain' => (boolean) $rain
			);
		}

		static::$data = $data;
	}

	public static function rainForecast()
	{
		$rain_check_days = Model::getRainCheckDays();
		$i = 0;

		foreach (static::$data as $data) {
			if ($i == $rain_check_days) break;
			if ($data['rain']) return true;
			$i++;
		}

		return false;
	}

	public static function getTemperature($basis)
	{
		return (int) static::$data[date('Y-m-d')][strtolower($basis)];
	}
}

class Curl
{
	public static function request($url, $headers = array(), $method = 'GET', $postfields = null)
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		if ($method == 'PUT') {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
			curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
		}
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		$result = curl_exec($ch);
		curl_close($ch);
		return json_decode($result);
	}
}

\W\Rachio::run();
