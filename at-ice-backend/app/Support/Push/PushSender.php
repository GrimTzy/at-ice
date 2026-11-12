<?php

namespace App\Support\Push;

// Sends one notice to a list of phones. Returns the tokens Firebase says are no longer valid
// (the app was removed or the phone was reset), so they can be forgotten.
interface PushSender
{
    /** @param  list<string>  $tokens  @return list<string> invalid tokens */
    public function send(array $tokens, string $title, string $body, array $data): array;
}
