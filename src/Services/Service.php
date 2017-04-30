<?php
namespace Worklog\Services;


use Worklog\Application;

/**
 * Service
 */
class Service {

	protected static $for = [];


	public static function register($class, $callable = null) {
		if (is_null($callable)) {
			static::get_for($class);
		} else {
			static::set_for($class, $callable);
		}
	}

	private static function get_for($class) {
		return static::$for[$class];
	}

	private static function set_for($class, $callable) {
		if (! is_callable($callable)) {
			throw new \InvalidArgumentException('Setting a "for" member requires a callable.');
		}

		static::$for[$class] = $callable;
	}

}