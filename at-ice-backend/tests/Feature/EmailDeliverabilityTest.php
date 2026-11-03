<?php

namespace Tests\Feature;

use App\Mail\AccountCredentials;
use App\Mail\DeliveryDelayNotice;
use App\Mail\KioskLocked;
use App\Mail\PasswordResetCode;
use Illuminate\Mail\Mailable;
use Tests\TestCase;

// Subjects stay free of words spam filters weigh, the sender name comes from
// config, and every email has an HTML and a plain-text part.
class EmailDeliverabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.from.name' => 'AT-ICE ERP', 'mail.company' => 'Bella Erin Tube Ice Inc.']);
    }

    private function reset(): PasswordResetCode
    {
        return new PasswordResetCode('482913', 15);
    }

    private function welcome(): AccountCredentials
    {
        return new AccountCredentials('Alfamart & Sons', 'shop@example.com', 'Tmp9xQ2wLp');
    }

    /** @return array<string, Mailable> */
    private function all(): array
    {
        return [
            'reset' => $this->reset(),
            'welcome' => $this->welcome(),
            'delay' => new DeliveryDelayNotice('Alfamart', 'DLV-0001', 'Your usual driver is off today.'),
            'kiosk' => new KioskLocked('KIOSK-01', '2026-11-03 08:00', '10.0.0.5'),
        ];
    }

    // [html, text] as the mailer would render them.
    private function parts(Mailable $mail): array
    {
        $html = $mail->render();
        $content = $mail->content();
        $text = view($content->text, $content->with + $mail->buildViewData())->render();

        return [$html, $text];
    }

    public function test_the_reset_subject_has_no_code_and_no_trigger_words(): void
    {
        $mail = $this->reset();

        $mail->assertHasSubject('Verification request for your AT-ICE ERP account');
        $subject = $mail->envelope()->subject;
        $this->assertStringNotContainsString('482913', $subject);
        $this->assertStringNotContainsStringIgnoringCase('password', $subject);
        $this->assertStringNotContainsStringIgnoringCase('credentials', $subject);
    }

    public function test_the_welcome_subject_has_no_trigger_words(): void
    {
        $mail = $this->welcome();

        $mail->assertHasSubject('Welcome to AT-ICE ERP: account activation');
        $subject = $mail->envelope()->subject;
        $this->assertStringNotContainsStringIgnoringCase('password', $subject);
        $this->assertStringNotContainsStringIgnoringCase('credentials', $subject);
        $this->assertStringNotContainsString('Tmp9xQ2wLp', $subject);
    }

    public function test_the_sender_name_follows_config(): void
    {
        config(['mail.from.name' => 'Another Name']);

        $this->reset()->assertHasSubject('Verification request for your Another Name account');
        $this->welcome()->assertHasSubject('Welcome to Another Name: account activation');
    }

    public function test_every_email_has_an_html_and_a_plain_text_part(): void
    {
        foreach ($this->all() as $key => $mail) {
            $this->assertNotEmpty($mail->content()->view, "$key has no HTML view");
            $this->assertNotEmpty($mail->content()->text, "$key has no plain-text view");

            [$html, $text] = $this->parts($mail);
            $this->assertStringContainsString('<html', $html, $key);
            $this->assertStringNotContainsString('<', $text, "$key plain text has HTML in it");
            $this->assertStringContainsString('Bella Erin Tube Ice Inc. · AT-ICE ERP', $text, "$key plain text footer");
            $this->assertStringContainsString('Bella Erin Tube Ice Inc.', $html, "$key HTML footer");
        }
    }

    public function test_the_plain_text_parts_carry_the_secret_and_are_not_html_escaped(): void
    {
        [, $text] = $this->parts($this->reset());
        $this->assertStringContainsString('482913', $text);
        $this->assertStringContainsString('15 minutes', $text);

        [, $text] = $this->parts($this->welcome());
        $this->assertStringContainsString('Hi Alfamart & Sons,', $text);
        $this->assertStringContainsString('Email: shop@example.com', $text);
        $this->assertStringContainsString('One-time password: Tmp9xQ2wLp', $text);
        $this->assertStringContainsString('/login', $text);
    }

    public function test_the_html_is_simple_one_link_at_most_and_no_remote_images(): void
    {
        foreach ($this->all() as $key => $mail) {
            [$html] = $this->parts($mail);
            $this->assertStringNotContainsString('<img', $html, $key);
            $this->assertLessThanOrEqual(1, substr_count($html, '<a '), "$key has more than one link");
        }
    }
}
