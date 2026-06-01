<?php

namespace App\Mail;

use App\Models\LicenseRequest;
use App\Models\LicenseSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LicenseCatalogMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly LicenseRequest $licenseRequest,
        public readonly LicenseSetting $settings,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu catálogo de licencias — AI Companion',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.license-catalog',
            with: [
                'request'        => $this->licenseRequest,
                'settings'       => $this->settings,
                'priceMonthly'   => number_format($this->settings->price_monthly_cop, 0, ',', '.'),
                'priceYearly'    => number_format($this->settings->price_yearly_cop, 0, ',', '.'),
                'yearlySavings'  => number_format(
                    ($this->settings->price_monthly_cop * 12) - $this->settings->price_yearly_cop,
                    0, ',', '.'
                ),
                'features'       => $this->settings->license_features ?? [],
                'whatsappNumber' => preg_replace('/[^0-9]/', '', $this->settings->whatsapp_number),
            ],
        );
    }
}
