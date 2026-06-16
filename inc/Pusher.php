<?php
namespace SevDeskBridge;

if (!defined('ABSPATH')) exit;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderMeta;

class Pusher
{
    const META_INVOICE_ID         = '_sevdesk_invoice_id';
    const META_INVOICE_NO         = '_sevdesk_invoice_no';
    const META_PUSHED_AT          = '_sevdesk_pushed_at';
    const META_CANCELED_AT        = '_sevdesk_canceled_at';
    const META_STORNO_INVOICE_ID  = '_sevdesk_storno_invoice_id';
    const META_STORNO_INVOICE_NO  = '_sevdesk_storno_invoice_no';
    const META_REFUND_VOUCHER_IDS = '_sevdesk_refund_voucher_ids';
    const META_PARENT_INVOICE_ID  = '_sevdesk_parent_invoice_id';
    const META_HISTORY            = '_sevdesk_history';

    const TAX_TEXT = 'Gemäß §19 UStG nicht ausgewiesen';

    public static function push(int $orderId, bool $force = false): array
    {
        $order = Order::with(['customer', 'order_items', 'billing_address'])->find($orderId);
        if (!$order) {
            throw new \RuntimeException("Order {$orderId} nicht gefunden.");
        }

        $existing = self::existingInvoice($orderId);
        if ($existing) {
            Logger::info($orderId, "Bereits gepusht (Invoice #{$existing['no']}, ID {$existing['id']}) — kein Re-Push.");
            return [
                'already_pushed' => true,
                'invoice_id'     => $existing['id'],
                'invoice_no'     => $existing['no'],
            ];
        }

        self::guardOrSkip($order, $force);

        try {
            $contactId = self::resolveContact($order);
            $invoice   = self::createInvoice($order, $contactId);

            if (!Client::isDryRun()) {
                self::storeMeta($orderId, [
                    self::META_INVOICE_ID => (int) $invoice['id'],
                    self::META_INVOICE_NO => (string) $invoice['invoiceNumber'],
                    self::META_PUSHED_AT  => current_time('mysql'),
                ]);

                self::recordParentInvoice($order);
            }

            try {
                self::bookPayment($order, $invoice);
                $finalNo = self::backfillInvoiceNumber($orderId, (int) $invoice['id']);
                if ($finalNo !== '') {
                    $invoice['invoiceNumber'] = $finalNo;
                }
            } catch (\Throwable $e) {
                Logger::warning($orderId, 'Rechnung angelegt, bookAmount fehlgeschlagen', [
                    'invoice_id'    => $invoice['id'] ?? null,
                    'invoice_no'    => $invoice['invoiceNumber'] ?? null,
                    'error'         => $e->getMessage(),
                ]);
                throw new \RuntimeException('Rechnung angelegt (#' . $invoice['invoiceNumber'] . '), Zahlung NICHT verbucht: ' . $e->getMessage());
            }

            $tag = Client::isDryRun() ? 'DRY-RUN ' : '';
            Logger::success($orderId, $tag . "Invoice #{$invoice['invoiceNumber']} angelegt + bezahlt verbucht", [
                'invoice_id' => (int) $invoice['id'],
                'invoice_no' => (string) $invoice['invoiceNumber'],
                'contact_id' => $contactId,
                'amount'     => round(((int) $order->total_amount) / 100, 2),
                'currency'   => $order->currency,
            ]);

            return [
                'already_pushed' => false,
                'invoice_id'     => (int) $invoice['id'],
                'invoice_no'     => (string) $invoice['invoiceNumber'],
            ];
        } catch (\Throwable $e) {
            Logger::failure($orderId, $e);
            throw $e;
        }
    }

    public static function cancel(int $orderId): array
    {
        $invoiceId = (int) self::metaValue($orderId, self::META_INVOICE_ID);
        if (!$invoiceId) {
            Logger::info($orderId, 'Cancel angefordert, aber keine Original-Invoice — Skip.');
            return ['canceled' => false, 'reason' => 'no_invoice'];
        }
        if (self::metaValue($orderId, self::META_STORNO_INVOICE_ID)) {
            Logger::info($orderId, 'Cancel angefordert, aber Storno bereits vorhanden — Skip.');
            return ['canceled' => false, 'reason' => 'already_canceled'];
        }

        $order      = Order::find($orderId);
        $refundDate = self::refundDate($order);

        try {
            // sevDesk cancelInvoice erzeugt eine Gutschrift (CreditNote), keine Storno-Rechnung.
            $res = Client::request('POST', '/Invoice/' . $invoiceId . '/cancelInvoice');
            $obj = $res['objects'] ?? [];
            // Response-Objekt = CreditNote (Felder: id, creditNoteNumber).
            $stornoId = (int) ($obj['id'] ?? 0);
            $stornoNo = (string) ($obj['creditNoteNumber'] ?? $obj['invoiceNumber'] ?? '');

            // EÜR-Abfluss: Gutschrift festschreiben + Auszahlung mit Erstattungsdatum buchen.
            // sevDesk wertet die Gutschrift als Ausgaben-Beleg -> Betrag positiv = Erstattung.
            $amount = $order ? round(((int) $order->total_amount) / 100, 2) : 0;
            self::settleCreditNote($orderId, $stornoId, $amount, $refundDate);

            if (!Client::isDryRun()) {
                self::storeMeta($orderId, [
                    self::META_CANCELED_AT       => current_time('mysql'),
                    self::META_STORNO_INVOICE_ID => $stornoId,
                    self::META_STORNO_INVOICE_NO => $stornoNo,
                ]);
            }

            Logger::success($orderId, "Gutschrift #{$stornoNo} (Storno) angelegt + verbucht (EÜR-Abfluss {$refundDate})", [
                'credit_note_id'   => $stornoId,
                'credit_note_no'   => $stornoNo,
                'original_invoice' => $invoiceId,
                'amount'           => $amount,
                'refund_date'      => $refundDate,
            ]);

            return ['canceled' => true, 'storno_id' => $stornoId, 'storno_no' => $stornoNo];
        } catch (\Throwable $e) {
            // sevDesk meldet bereits existierende Gutschrift mit HTTP 422 "CreditNote exists" (code 170).
            if (strpos($e->getMessage(), 'CreditNote exists') !== false) {
                $existing = self::extractExistingCreditNote($e->getMessage());
                if (!Client::isDryRun() && $existing) {
                    self::storeMeta($orderId, [
                        self::META_CANCELED_AT       => current_time('mysql'),
                        self::META_STORNO_INVOICE_ID => $existing['id'],
                        self::META_STORNO_INVOICE_NO => $existing['no'],
                    ]);
                }
                Logger::info($orderId, 'Gutschrift existiert bereits — übernommen.', $existing);
                return ['canceled' => false, 'reason' => 'already_canceled', 'storno_no' => $existing['no'] ?? ''];
            }
            Logger::failure($orderId, $e);
            throw $e;
        }
    }

    protected static function extractExistingCreditNote(string $msg): array
    {
        // Fehler-JSON aus Exception-Message extrahieren (Client wirft "...HTTP 422: {json}").
        $pos = strpos($msg, '{');
        if ($pos === false) return [];
        $json = json_decode(substr($msg, $pos), true);
        $cn = $json['error']['data']['objects'][0] ?? null;
        if (!$cn) return [];
        return [
            'id' => (int) ($cn['id'] ?? 0),
            'no' => (string) ($cn['creditNoteNumber'] ?? ''),
        ];
    }

    public static function storedInvoiceNo(int $orderId): string
    {
        return (string) self::metaValue($orderId, self::META_INVOICE_NO);
    }

    /**
     * Storno des Stornos: Wiederübertragung der Rechnung.
     * Ausgestelltes Storno (Gutschrift) ist GoBD-immutable und bleibt bestehen.
     * Statt Rückgängigmachen wird eine NEUE Rechnung erzeugt (GoBD-Kette: Verkauf->Storno->Neu-Verkauf).
     * Altes RE+GU wird in _sevdesk_history archiviert.
     */
    public static function rePush(int $orderId): array
    {
        if (!self::metaValue($orderId, self::META_CANCELED_AT)) {
            Logger::info($orderId, 'Neu-Übertragung angefordert, aber Order ist nicht storniert — Skip.');
            return ['repushed' => false, 'reason' => 'not_canceled'];
        }

        // Aktuellen (stornierten) Stand archivieren.
        $archive = [
            'invoice_id'  => self::metaValue($orderId, self::META_INVOICE_ID),
            'invoice_no'  => self::metaValue($orderId, self::META_INVOICE_NO),
            'pushed_at'   => self::metaValue($orderId, self::META_PUSHED_AT),
            'storno_id'   => self::metaValue($orderId, self::META_STORNO_INVOICE_ID),
            'storno_no'   => self::metaValue($orderId, self::META_STORNO_INVOICE_NO),
            'canceled_at' => self::metaValue($orderId, self::META_CANCELED_AT),
            'archived_at' => current_time('mysql'),
        ];
        self::appendHistory($orderId, $archive);

        // Invoice- + Storno-Meta löschen, damit push() eine frische Rechnung erzeugt.
        self::deleteMeta($orderId, [
            self::META_INVOICE_ID, self::META_INVOICE_NO, self::META_PUSHED_AT,
            self::META_CANCELED_AT, self::META_STORNO_INVOICE_ID, self::META_STORNO_INVOICE_NO,
        ]);

        try {
            $res = self::push($orderId, true); // force: ignoriert erstattet-Status
            Logger::success($orderId, "Rechnung neu übertragen (#{$res['invoice_no']}) — altes Storno {$archive['storno_no']} archiviert", [
                'new_invoice_no'    => $res['invoice_no'] ?? '',
                'archived_invoice'  => $archive['invoice_no'],
                'archived_storno'   => $archive['storno_no'],
            ]);
            return ['repushed' => true] + $res;
        } catch (\Throwable $e) {
            Logger::failure($orderId, $e);
            throw $e;
        }
    }

    protected static function appendHistory(int $orderId, array $entry): void
    {
        if (Client::isDryRun()) return;
        $raw = self::metaValue($orderId, self::META_HISTORY);
        $arr = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $arr[] = $entry;
        self::storeMeta($orderId, [self::META_HISTORY => wp_json_encode($arr)]);
    }

    protected static function deleteMeta(int $orderId, array $keys): void
    {
        if (Client::isDryRun()) return;
        foreach ($keys as $key) {
            OrderMeta::where('order_id', $orderId)->where('meta_key', $key)->delete();
        }
    }

    /**
     * Gutschrift festschreiben (sendBy -> 200) + Auszahlung buchen (bookAmount -> Abfluss).
     */
    protected static function settleCreditNote(int $orderId, int $creditNoteId, float $amount, string $dateDe): void
    {
        if (!$creditNoteId && !Client::isDryRun()) {
            throw new \RuntimeException('settleCreditNote ohne CreditNote-ID.');
        }
        $accountId = Client::checkAccountId();
        if (!$accountId && !Client::isDryRun()) {
            throw new \RuntimeException('SEVDESK_CHECK_ACCOUNT_ID nicht gesetzt.');
        }
        if (!$accountId) $accountId = 999999;

        // 1. Festschreiben (Status 200) — bookAmount verweigert Entwürfe.
        Client::request('PUT', '/CreditNote/' . $creditNoteId . '/sendBy', [
            'sendType'  => 'VPR',
            'sendDraft' => false,
        ]);

        // 2. Auszahlung buchen (EÜR-Abfluss mit Erstattungsdatum).
        $payload = [
            'amount'       => $amount,
            'date'         => $dateDe,
            'type'         => 'N',
            'checkAccount' => ['id' => $accountId, 'objectName' => 'CheckAccount'],
            'createFeed'   => true,
        ];
        if (Client::isDryRun()) {
            Logger::dryRun($orderId, 'CreditNote bookAmount Payload', $payload);
        }
        Client::request('PUT', '/CreditNote/' . $creditNoteId . '/bookAmount', $payload);
    }

    protected static function refundDate($order): string
    {
        $when = ($order && $order->refunded_at) ? $order->refunded_at : null;
        return $when ? gmdate('d.m.Y', strtotime((string) $when)) : gmdate('d.m.Y');
    }

    protected static function guardOrSkip($order, bool $force = false): void
    {
        $orderId = (int) $order->id;

        $items = $order->order_items ?? null;
        if (!$items || (is_countable($items) && count($items) === 0)) {
            $msg = 'Order hat keine Items — Skip.';
            Logger::warning($orderId, $msg);
            throw new \RuntimeException($msg);
        }

        $currency = strtoupper((string) ($order->currency ?: 'EUR'));
        if ($currency !== 'EUR') {
            $msg = "Order-Währung {$currency} ≠ EUR — Skip (Non-EUR Support nicht implementiert).";
            Logger::warning($orderId, $msg);
            throw new \RuntimeException($msg);
        }

        // Re-Push (force) ignoriert den erstattet-Status: Wiederübertragung trotz Refund gewollt.
        if (!$force && in_array((string) $order->payment_status, ['refunded', 'fully_refunded', 'partially_refunded'], true)) {
            $msg = "Order-Payment-Status '{$order->payment_status}' — vor Push erstattet, Skip.";
            Logger::warning($orderId, $msg);
            throw new \RuntimeException($msg);
        }
    }

    protected static function existingInvoice(int $orderId): ?array
    {
        $id = self::metaValue($orderId, self::META_INVOICE_ID);
        if (!$id) return null;
        $no = self::metaValue($orderId, self::META_INVOICE_NO);
        return ['id' => (int) $id, 'no' => (string) $no];
    }

    protected static function metaValue(int $orderId, string $key)
    {
        return OrderMeta::where('order_id', $orderId)->where('meta_key', $key)->value('meta_value');
    }

    protected static function storeMeta(int $orderId, array $kv): void
    {
        foreach ($kv as $key => $val) {
            OrderMeta::updateOrCreate(
                ['order_id' => $orderId, 'meta_key' => $key],
                ['meta_value' => (string) $val]
            );
        }
    }

    protected static function recordParentInvoice($order): void
    {
        if (($order->type ?? '') !== 'renewal' || empty($order->parent_id)) return;
        $parentInvoiceId = self::metaValue((int) $order->parent_id, self::META_INVOICE_ID);
        if (!$parentInvoiceId) return;
        self::storeMeta((int) $order->id, [self::META_PARENT_INVOICE_ID => (int) $parentInvoiceId]);
    }

    protected static function resolveContact($order): int
    {
        $customer = $order->customer;
        $email    = $customer->email ?? '';
        if (!$email) {
            throw new \RuntimeException('Order hat keine Customer-Email.');
        }

        $found = Client::request('GET', '/Contact', null, [
            'email' => $email,
            'depth' => 1,
            'limit' => 10,
        ]);

        $candidates = $found['objects'] ?? [];
        if ($candidates) {
            $id = self::pickContact($candidates, $order);
            if ($id) return $id;
        }

        $billing  = self::pickAddress($order, 'billing');
        $fullName = trim((string) ($billing['name'] ?? ''));
        $company  = trim((string) ($billing['company'] ?? ''));

        if (!$fullName) {
            $first = trim((string) ($customer->first_name ?? ''));
            $last  = trim((string) ($customer->last_name ?? ''));
            $fullName = trim($first . ' ' . $last);
        }

        $nameParts = self::splitName($fullName);

        $contactBody = [
            'category' => ['id' => 3, 'objectName' => 'Category'],
        ];
        if ($company) {
            $contactBody['name']       = $company;
            $contactBody['surename']   = $nameParts['first'];
            $contactBody['familyname'] = $nameParts['last'];
        } else {
            $contactBody['name']       = $fullName ?: $email;
            $contactBody['surename']   = $nameParts['first'];
            $contactBody['familyname'] = $nameParts['last'];
        }

        $created   = Client::request('POST', '/Contact', $contactBody);
        $contactId = (int) ($created['objects']['id'] ?? 0);
        if (!$contactId) {
            throw new \RuntimeException('sevDesk Contact-Erstellung fehlgeschlagen: ' . wp_json_encode($created));
        }

        Client::request('POST', '/CommunicationWay', [
            'contact' => ['id' => $contactId, 'objectName' => 'Contact'],
            'type'    => 'EMAIL',
            'value'   => $email,
            'key'     => ['id' => 2, 'objectName' => 'CommunicationWayKey'],
        ]);

        if (!empty($billing['street']) || !empty($billing['city'])) {
            Client::request('POST', '/ContactAddress', [
                'contact'  => ['id' => $contactId, 'objectName' => 'Contact'],
                'street'   => (string) ($billing['street'] ?? ''),
                'zip'      => (string) ($billing['zip'] ?? ''),
                'city'     => (string) ($billing['city'] ?? ''),
                'country'  => ['id' => self::countryId((string) ($billing['country'] ?? 'DE')), 'objectName' => 'StaticCountry'],
            ]);
        }

        return $contactId;
    }

    protected static function pickContact(array $candidates, $order): int
    {
        if (count($candidates) === 1) {
            return (int) $candidates[0]['id'];
        }

        $billing  = self::pickAddress($order, 'billing');
        $fullName = strtolower(trim((string) ($billing['name'] ?? '')));
        if ($fullName) {
            foreach ($candidates as $c) {
                $candidate = strtolower(trim((string) ($c['surename'] ?? '') . ' ' . (string) ($c['familyname'] ?? '')));
                if ($candidate && $candidate === $fullName) {
                    Logger::info((int) $order->id, "Contact-Tiebreaker via Namens-Match (ID {$c['id']})");
                    return (int) $c['id'];
                }
            }
        }

        usort($candidates, function ($a, $b) {
            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        });
        Logger::warning((int) $order->id, 'Mehrere sevDesk-Contacts für Email — nehme neuesten', [
            'candidate_ids' => array_map(fn($c) => $c['id'] ?? null, $candidates),
            'chosen'        => $candidates[0]['id'] ?? null,
        ]);
        return (int) $candidates[0]['id'];
    }

    protected static function createInvoice($order, int $contactId): array
    {
        $billing  = self::pickAddress($order, 'billing');
        $when     = $order->completed_at ?: $order->created_at;
        $invDate  = $when ? gmdate('d.m.Y', strtotime((string) $when)) : gmdate('d.m.Y');

        $sevUser = ['id' => self::firstSevUserId(), 'objectName' => 'SevUser'];

        $invoice = [
            'objectName'         => 'Invoice',
            'mapAll'             => true,
            'invoiceNumber'      => '',
            'contact'            => ['id' => $contactId, 'objectName' => 'Contact'],
            'contactPerson'      => $sevUser,
            'invoiceDate'        => $invDate,
            'deliveryDate'       => $invDate,
            'status'             => 100,
            'header'             => 'Rechnung zu Bestellung #' . ($order->invoice_no ?: $order->id),
            'addressName'        => (string) ($billing['name'] ?? '') ?: ($order->customer->email ?? ''),
            'addressStreet'      => (string) ($billing['street'] ?? ''),
            'addressZip'         => (string) ($billing['zip'] ?? ''),
            'addressCity'        => (string) ($billing['city'] ?? ''),
            'addressCountry'     => ['id' => self::countryId((string) ($billing['country'] ?? 'DE')), 'objectName' => 'StaticCountry'],
            'smallSettlement'    => 1,
            'taxRate'            => 0,
            'taxText'            => self::TAX_TEXT,
            'taxType'            => 'ss',
            'invoiceType'        => 'RE',
            'currency'           => (string) ($order->currency ?: 'EUR'),
            'timeToPay'          => 0,
            'showNet'            => 0,
        ];

        $bookingText = self::bookingMetaText($order);

        $positions = [];
        foreach ($order->order_items as $i => $item) {
            $qty     = (float) ($item->quantity ?? 1);
            $netCent = (int) ($item->line_total ?? 0);
            $unit    = $qty > 0 ? round(($netCent / 100) / $qty, 4) : ($netCent / 100);

            $name = (string) ($item->post_title ?? '');
            if (!$name) {
                $name = 'Artikel #' . ($item->id ?? $i);
            }

            // Buchungs-Metadaten nur an erste Position (Order-Level-Daten, sonst redundant).
            $text = $i === 0 ? $bookingText : '';

            $positions[] = [
                'objectName'     => 'InvoicePos',
                'mapAll'         => true,
                'name'           => $name,
                'text'           => $text,
                'quantity'       => $qty,
                'price'          => $unit,
                'taxRate'        => 0,
                'unity'          => ['id' => 1, 'objectName' => 'Unity'],
                'priceGross'     => 0,
                'priceNet'       => 0,
                'priceTax'       => 0,
                'positionNumber' => $i,
                'discount'       => 0,
            ];
        }

        if (!$positions) {
            throw new \RuntimeException('Order hat keine Items.');
        }

        $payload = [
            'invoice'          => $invoice,
            'invoicePosSave'   => $positions,
            'invoicePosDelete' => null,
        ];

        if (Client::isDryRun()) {
            Logger::dryRun((int) $order->id, 'saveInvoice Payload', $payload);
        }

        $res = Client::request('POST', '/Invoice/Factory/saveInvoice', $payload);
        $obj = $res['objects']['invoice'] ?? null;
        if (!$obj || !isset($obj['id'])) {
            throw new \RuntimeException('saveInvoice ohne Invoice-Objekt: ' . wp_json_encode($res));
        }
        return $obj;
    }

    protected static function bookPayment($order, array $invoice): void
    {
        $accountId = Client::checkAccountId();
        if (!$accountId && !Client::isDryRun()) {
            throw new \RuntimeException('SEVDESK_CHECK_ACCOUNT_ID nicht gesetzt.');
        }
        if (!$accountId) $accountId = 999999;

        $amount = round(((int) $order->total_amount) / 100, 2);
        $when   = $order->completed_at ?: $order->created_at;
        $date   = $when ? gmdate('d.m.Y', strtotime((string) $when)) : gmdate('d.m.Y');

        // Entwurf (Status 100) → Offen (Status 200): Rechnung als "versendet" markieren.
        // bookAmount verweigert Entwürfe ("A draft can not be paid").
        Client::request('PUT', '/Invoice/' . (int) $invoice['id'] . '/sendBy', [
            'sendType'  => 'VPR',
            'sendDraft' => false,
        ]);

        $payload = [
            'amount'       => $amount,
            'date'         => $date,
            'type'         => 'N',
            'checkAccount' => ['id' => $accountId, 'objectName' => 'CheckAccount'],
            'createFeed'   => true,
        ];

        if (Client::isDryRun()) {
            Logger::dryRun((int) $order->id, 'bookAmount Payload', $payload);
        }

        Client::request('PUT', '/Invoice/' . (int) $invoice['id'] . '/bookAmount', $payload);
    }

    protected static function bookingMetaText($order): string
    {
        $orderNo = (string) ($order->invoice_no ?: $order->id);

        $created = $order->created_at ? gmdate('d.m.Y', strtotime((string) $order->created_at)) : '';
        $paid    = $order->completed_at ? gmdate('d.m.Y', strtotime((string) $order->completed_at)) : '';
        $payTitle = (string) ($order->payment_method_title ?: $order->payment_method ?: '');

        $lines = [];

        $termin = self::appointmentLine($order);
        if ($termin !== '') {
            $lines[] = $termin;
        }

        $lines[] = 'Bestellung: ' . $orderNo . ($created ? ' vom ' . $created : '');
        if ($paid) {
            $lines[] = 'Bezahlt am ' . $paid . ($payTitle ? ' · Zahlungsart: ' . $payTitle : '');
        } elseif ($payTitle) {
            $lines[] = 'Zahlungsart: ' . $payTitle;
        }

        return implode("\n", $lines);
    }

    protected static function appointmentLine($order): string
    {
        $config = $order->config ?? null;
        if (is_string($config)) {
            $config = json_decode($config, true) ?: [];
        } elseif (!is_array($config)) {
            $config = [];
        }
        $bookingId = (int) ($config['fcal_booking_id'] ?? 0);
        if (!$bookingId) return '';

        if (!class_exists('\FluentBooking\App\Models\Booking')) return '';

        try {
            $booking = \FluentBooking\App\Models\Booking::find($bookingId);
            if (!$booking) return '';

            $tz = (string) ($booking->person_time_zone ?: 'Europe/Berlin');
            try {
                $zone = new \DateTimeZone($tz);
            } catch (\Throwable $e) {
                $zone = new \DateTimeZone('Europe/Berlin');
            }

            $start = $booking->start_time ? new \DateTime((string) $booking->start_time, new \DateTimeZone('UTC')) : null;
            $end   = $booking->end_time   ? new \DateTime((string) $booking->end_time,   new \DateTimeZone('UTC')) : null;
            if (!$start) return '';
            $start->setTimezone($zone);
            if ($end) $end->setTimezone($zone);

            $datum = $start->format('d.m.Y');
            $von    = $start->format('H:i');
            $bis    = $end ? $end->format('H:i') : '';
            $spanne = $bis ? "{$von}–{$bis} Uhr" : "{$von} Uhr";

            return 'Termin: ' . $datum . ', ' . $spanne;
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected static function backfillInvoiceNumber(int $orderId, int $invoiceId): string
    {
        if (Client::isDryRun() || !$invoiceId) return '';
        try {
            $res = Client::request('GET', '/Invoice/' . $invoiceId);
            $no  = (string) ($res['objects'][0]['invoiceNumber'] ?? '');
            if ($no !== '') {
                self::storeMeta($orderId, [self::META_INVOICE_NO => $no]);
            }
            return $no;
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected static function pickAddress($order, string $type): array
    {
        $addr = null;
        if ($type === 'billing') {
            $addr = $order->billing_address ?? null;
        }
        if (!$addr) {
            foreach ($order->order_addresses ?? [] as $candidate) {
                if (($candidate->type ?? '') === $type) {
                    $addr = $candidate;
                    break;
                }
            }
        }
        if (!$addr) return [];

        $meta = $addr->meta ?? null;
        if (is_string($meta)) {
            $meta = json_decode($meta, true) ?: [];
        } elseif (!is_array($meta)) {
            $meta = [];
        }

        return [
            'name'    => trim((string) ($addr->name ?? '')),
            'company' => (string) ($meta['company'] ?? ''),
            'street'  => trim(((string) ($addr->address_1 ?? '')) . ' ' . ((string) ($addr->address_2 ?? ''))),
            'zip'     => (string) ($addr->postcode ?? ''),
            'city'    => (string) ($addr->city ?? ''),
            'country' => (string) ($addr->country ?? 'DE'),
        ];
    }

    protected static function splitName(string $fullName): array
    {
        $fullName = trim($fullName);
        if (!$fullName) return ['first' => '', 'last' => ''];
        $parts = preg_split('/\s+/', $fullName);
        if (count($parts) === 1) return ['first' => $parts[0], 'last' => ''];
        $last  = array_pop($parts);
        $first = implode(' ', $parts);
        return ['first' => $first, 'last' => $last];
    }

    protected static function countryId(string $iso): int
    {
        $map = [
            'DE' => 1, 'AT' => 2, 'CH' => 3, 'BE' => 4, 'BG' => 5, 'DK' => 6,
            'EE' => 7, 'FI' => 8, 'FR' => 9, 'GR' => 10, 'IE' => 11, 'IT' => 12,
            'HR' => 13, 'LV' => 14, 'LT' => 15, 'LU' => 16, 'MT' => 17, 'NL' => 18,
            'PL' => 19, 'PT' => 20, 'RO' => 21, 'SE' => 22, 'SK' => 23, 'SI' => 24,
            'ES' => 25, 'CZ' => 26, 'HU' => 27, 'GB' => 28, 'US' => 117,
        ];
        return $map[strtoupper($iso)] ?? 1;
    }

    protected static function firstSevUserId(): int
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        try {
            $res = Client::request('GET', '/SevUser', null, ['limit' => 1]);
            $cached = (int) ($res['objects'][0]['id'] ?? 0);
        } catch (\Throwable $e) {
            $cached = 0;
        }
        return $cached;
    }
}
