<?php

return [

    // Where commits go. Only https:// is accepted, apart from a local development server.
    'url' => env('DEVNULL_URL', 'https://dev-null.sh'),

    // The public key to sign with: a path to a .pub file whose private half is beside it or in
    // the SSH agent. Pinned, so that a commit never sends /dev/null a list of every key on the
    // machine - `devnull keys` is the one command that does, on purpose.
    'key' => env('DEVNULL_KEY'),

];
