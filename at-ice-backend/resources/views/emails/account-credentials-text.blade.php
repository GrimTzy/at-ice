Welcome to {!! config('mail.from.name') !!}

Hi {!! $name !!}, your customer portal account is ready. Sign in with:

Email: {!! $email !!}
One-time password: {!! $password !!}

After you sign in, you will be asked to choose your own password. If you forget it later, click "Forgot password?" on the sign-in page.

Sign in: {!! $loginUrl !!}

Do not share this email with anyone.

@include('emails.partials.footer-text')
