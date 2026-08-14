<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\UserSettings;

/**
 * Tool: set the seller/company details used on generated invoices (name, CVR, address,
 * email, payment info). Only the fields provided are updated; passing an empty string
 * clears a field.
 */
final class SetCompanyProfile implements Tool
{
    public function __construct(private UserSettings $settings)
    {
    }

    public function name(): string
    {
        return 'set_company_profile';
    }

    public function description(): string
    {
        return 'Sets your business details used as the sender on invoices you generate: company name, CVR '
            . 'number, address, contact email, and payment details (bank reg/konto, IBAN or MobilePay). Use '
            . 'when the user gives their company info or says "set my company details", "min virksomhed er …", '
            . '"mit CVR er …", "set my payment details". Only updates the fields provided.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'name'    => ['type' => 'string', 'description' => 'Business name (the invoice sender).'],
                'cvr'     => ['type' => 'string', 'description' => 'CVR number.'],
                'address' => ['type' => 'string', 'description' => 'Business address (street, postcode, city).'],
                'email'   => ['type' => 'string', 'description' => 'Contact email.'],
                'payment' => ['type' => 'string', 'description' => 'How clients pay — bank reg/konto, IBAN or MobilePay.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $map = ['name' => 'company_name', 'cvr' => 'company_cvr', 'address' => 'company_address',
            'email' => 'company_email', 'payment' => 'company_payment'];
        $updated = [];
        foreach ($map as $arg => $key) {
            if (array_key_exists($arg, $arguments)) {
                $this->settings->set($userId, $key, (string) $arguments[$arg]);
                $updated[] = $arg;
            }
        }

        return [
            'updated' => $updated,
            'profile' => $this->settings->companyProfile($userId),
        ];
    }
}
