<?php

namespace App\Mail;

use App\Models\License;
use App\Models\LicenseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LicenseActivatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly License        $license,
        public readonly LicenseRequest $licenseRequest,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🎉 Tu licencia de AI Companion está activa',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.license-activated',
            with: [
                'license'        => $this->license,
                'request'        => $this->licenseRequest,
                'typeLabel'      => match($this->license->type) {
                    'monthly' => 'Mensual',
                    'yearly'  => 'Anual',
                    default   => 'Personalizada',
                },
                'startsAt'       => $this->license->starts_at->format('d/m/Y'),
                'expiresAt'      => $this->license->expires_at->format('d/m/Y'),
                'daysRemaining'  => $this->license->daysRemaining(),
                'appUrl'         => config('app.url'),
            ],
        );
    }
}
