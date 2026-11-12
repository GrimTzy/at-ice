Your verification code

Someone asked to reset the password of your {!! config('mail.from.name') !!} account. Enter this code on the sign-in page to choose a new password:

    {!! $code !!}

The code works for {!! $minutes !!} minutes and only once.

If you did not ask for this, ignore this email; your password stays the same. Never share this code with anyone.

@include('emails.partials.footer-text')
