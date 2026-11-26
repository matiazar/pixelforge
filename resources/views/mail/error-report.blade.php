Unhandled exception in {{ config('app.name') }} ({!! $environment !!})

Exception: {!! $exceptionClass !!}
Message: {!! $errorMessage !!}
Location: {!! $location !!}
Request: {!! $url !!}
Time (UTC): {{ now('UTC')->toDateTimeString() }}

Full trace is in the application log.
