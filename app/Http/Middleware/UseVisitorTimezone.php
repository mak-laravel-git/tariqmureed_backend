<?php

namespace App\Http\Middleware;

use Closure;
use DateTimeZone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers in the visitor's time zone when the website sends one (?tz=Europe/London), so that "today" and the
 * dates match their calendar. Without a usable one the configured display time zone stays.
 */
class UseVisitorTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        $timezone = $request->query('tz');

        // Browsers still report some old names (Asia/Calcutta), so those are accepted too.
        if (is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            config(['sportmonks.display_timezone' => $timezone]);
        }

        return $next($request);
    }
}
