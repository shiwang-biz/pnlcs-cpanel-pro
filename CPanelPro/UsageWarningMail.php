<?php

namespace Modules\Servers\CPanelPro;

use App\Models\Service;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class UsageWarningMail extends Mailable
{
    /** @param array{resource:string, percent:int, used:string, limit:string, level:int} $usage */
    public function __construct(public Service $service, public array $usage) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->usage['resource']} {$this->usage['percent']}% used: ".($this->service->domain ?: 'your hosting'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'cpanelpro::emails.usage-warning', with: [
            'service' => $this->service,
            'usage' => $this->usage,
            'url' => route('client.services.show', $this->service),
            'companyName' => config('app.name'),
        ]);
    }
}
