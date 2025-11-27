<?php
/**
 * Invoice Ninja (https://invoiceninja.com)
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2025. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Helpers\Cache;

use App\Utils\Ninja;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class Atomic
{
    public static function set($key, $value = true, $ttl = 1)
    {
        if (Ninja::isHosted()) {
            try {
                return Redis::connection('sentinel-cache')->set($key, $value, 'NX', 'EX', $ttl);
            } catch (\Throwable) {
                return Cache::add($key, $value, $ttl);
            }
        }

        if (Cache::has($key)) {
            return false;
        }

        return Cache::add($key, $value, $ttl);
    }

    public static function get($key)
    {
        if (Ninja::isHosted()) {
            try {
                return Redis::connection('sentinel-cache')->get($key);
            } catch (\Throwable) {
                return Cache::get($key);
            }
        }

        return Cache::get($key);
    }

    public static function del($key)
    {
        if (Ninja::isHosted()) {
            try {
                return Redis::connection('sentinel-cache')->del($key);
            } catch (\Throwable) {
                return Cache::forget($key);
            }
        }

        return Cache::forget($key);
    }
}