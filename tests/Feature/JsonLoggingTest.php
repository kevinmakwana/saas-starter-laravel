<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->logFile = tempnam(sys_get_temp_dir(), 'json-log');
    config(['logging.channels.json.handler_with.stream' => $this->logFile]);
    Log::forgetChannel('json');
});

afterEach(fn () => @unlink($this->logFile));

it('writes one JSON object per line with the message and context', function () {
    Log::channel('json')->warning('Payment failed for {team}', ['team' => 7]);

    $line = json_decode(trim(file_get_contents($this->logFile)), true, flags: JSON_THROW_ON_ERROR);

    expect($line)->toMatchArray(['message' => 'Payment failed for 7', 'level_name' => 'WARNING'])
        ->and($line['context'])->toMatchArray(['team' => 7]);
});

it('includes the request, user and team ids from the Context', function () {
    Context::add(['request_id' => 'req-1', 'user_id' => 5, 'team_id' => 9]);

    Log::channel('json')->info('hello');

    $line = json_decode(trim(file_get_contents($this->logFile)), true, flags: JSON_THROW_ON_ERROR);

    expect($line['extra'] ?? $line['context'])->toMatchArray(['request_id' => 'req-1', 'user_id' => 5, 'team_id' => 9]);
});
