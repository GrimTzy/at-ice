A kiosk is locked

The Attendance Station kiosk below was locked after too many wrong PINs in a row. Nobody can record attendance on it until it is unlocked.

Kiosk: {!! $kiosk !!}
Locked at: {!! $lockedAt !!}
IP address: {!! $ip !!}

An Admin unlocks it by typing the Admin override PIN on that kiosk. If nobody at the store was entering the PIN, someone may be trying to guess it.

@include('emails.partials.footer-text')
