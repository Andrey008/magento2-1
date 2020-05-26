<?php

namespace MundiPagg\MundiPagg\Concrete;

use JsonSerializable;
use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order\Invoice;
use Mundipagg\Core\Kernel\Abstractions\AbstractInvoiceDecorator;
use Mundipagg\Core\Kernel\Interfaces\PlatformOrderInterface;
use Mundipagg\Core\Kernel\Repositories\OrderRepository;
use Mundipagg\Core\Kernel\Services\LocalizationService;
use Mundipagg\Core\Kernel\Services\MoneyService;
use Mundipagg\Core\Kernel\ValueObjects\InvoiceState;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Framework\DB\Transaction;


class Magento2PlatformInvoiceDecorator extends AbstractInvoiceDecorator implements
    JsonSerializable
{
    public function save()
    {
        $this->platformInvoice->save();
    }

    public function loadByIncrementId($incrementId)
    {
        // TODO: Implement loadByIncrementId() method.
    }

    public function getIncrementId()
    {
        return $this->platformInvoice->getIncrementId();
    }

    public function prepareFor(PlatformOrderInterface $order, $itemsToInvoice)
    {
        $platformOrder = $order->getPlatformOrder();
        $invoiceService =
            ObjectManager::
                getInstance()
                ->get('Magento\Sales\Model\Service\InvoiceService');

        $this->platformInvoice = $invoiceService->prepareInvoice
        (
            $platformOrder,
            $itemsToInvoice
        );
    }

    public function createFor(PlatformOrderInterface $order)
    {
        $itemsToInvoice = $this->getItemsToInvoice($order);

        $this->prepareFor($order, $itemsToInvoice);
        $this->platformInvoice->setRequestedCaptureCase(
            \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE
        );
        $this->platformInvoice->register();

        $grandTotal = $order->getTotalPaidFromCharges();
        $this->platformInvoice->setBaseGrandTotal($grandTotal);
        $this->platformInvoice->setGrandTotal($grandTotal);

        $orderGrandTotal = $order->getGrandTotal();
        $moneyService = new MoneyService();
        $orderGrandTotal = $moneyService->floatToCents($orderGrandTotal);
        $orderGrandTotal = $moneyService->centsToFloat($orderGrandTotal);

        if ($grandTotal !== $orderGrandTotal) {
            $i18n = new LocalizationService();
            $comment = $i18n->getDashboard(
                "Different paid amount for this invoice. Paid value: %.2f",
                $grandTotal
            );

            $this->addComment($comment);
        }

        $this->save();
        $transactionSave = ObjectManager::getInstance()->get('Magento\Framework\DB\Transaction');
        $transactionSave->addObject(
            $this->platformInvoice
        )->addObject(
            $this->platformInvoice->getOrder()
        );
        $transactionSave->save();

        $objectManager = ObjectManager::getInstance();
        $invoiceSender = $objectManager->get(InvoiceSender::class);
        $invoiceSender->send($this->platformInvoice);
    }

    public function setState(InvoiceState $state)
    {
        $mageState = Invoice::STATE_PAID;

        if ($state->equals(InvoiceState::canceled())) {
            $mageState = Invoice::STATE_CANCELED;
        }

        $this->platformInvoice->setState($mageState);
    }

    public function canRefund()
    {
        return $this->platformInvoice->canRefund();
    }

    public function isCanceled()
    {
        return $this->platformInvoice->isCanceled();
    }

    private function createInvoice($order)
    {
        $objectManager = ObjectManager::getInstance();
        $invoiceService = $objectManager->get(InvoiceService::class);
        $transaction = $objectManager->get(Transaction::class);
        $invoiceSender = $objectManager->get(InvoiceSender::class);

        $itemsArray = [
            $order->getIncrementId() =>
            count($order->getItemCollection())
        ];

        $invoice = $invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $invoice->save();
        $transactionSave = $transaction->addObject(
            $invoice
        )->addObject(
            $invoice->getOrder()
        );
        $transactionSave->save();


        $order->addStatusHistoryComment(
            'MP - ' .
            __('Notified customer about invoice #%1.', $invoice->getIncrementId())
        )
            ->setIsCustomerNotified(true)
            ->save();

        $payment = $order->getPayment();
        $payment
            ->setIsTransactionClosed(true)
            ->registerCaptureNotification(
                $order->getGrandTotal(),
                true
            );

        $order->setState('processing')->setStatus('processing');
        $order->save();

        return $invoice;
    }

    /**
     * Specify data which should be serialized to JSON
     * @link https://php.net/manual/en/jsonserializable.jsonserialize.php
     * @return mixed data which can be serialized by <b>json_encode</b>,
     * which is a value of any type other than a resource.
     * @since 5.4.0
     */
    public function jsonSerialize()
    {
        return $this->platformInvoice->getData();
    }

    protected function addMPComment($comment)
    {
        $this->platformInvoice->addComment($comment);
    }

    protected function getItemsToInvoice(PlatformOrderInterface $order)
    {
        $orderItems = $order->getPlatformOrder()->getItems();
        $itemsToInvoice = [];
        foreach ($orderItems as $item) {
            $itemsToInvoice[$item->getId()] = $item->getQtyOrdered();
        }

        return $itemsToInvoice;
    }
}