<?php

namespace App\Payment;

class PaymentResult
{
    const TYPE_REDIRECT = 'redirect';

    const TYPE_QRCODE = 'qrcode';

    const TYPE_FORM = 'form';

    public function __construct(
        public string $type,
        public ?string $redirectUrl = null,
        public ?string $qrcodeContent = null,
        public ?string $formHtml = null,
        public ?string $currencySent = null,
        public ?int $amountSent = null,
    ) {}

    public static function redirect(
        string $url,
        ?string $currencySent = null,
        ?int $amountSent = null,
    ): static {
        return new static(
            self::TYPE_REDIRECT,
            redirectUrl: $url,
            currencySent: $currencySent,
            amountSent: $amountSent,
        );
    }

    public static function qrcode(string $content): static
    {
        return new static(self::TYPE_QRCODE, qrcodeContent: $content);
    }

    public static function form(string $html): static
    {
        return new static(self::TYPE_FORM, formHtml: $html);
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'redirect_url' => $this->redirectUrl,
            'qrcode_content' => $this->qrcodeContent,
            'form_html' => $this->formHtml,
            'currency_sent' => $this->currencySent,
            'amount_sent' => $this->amountSent,
        ];
    }
}
