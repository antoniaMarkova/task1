<?php
declare(strict_types=1);

namespace src\classes;

class Fees
{
    // conversio rates currency to EUR
    protected const CONVERSION_RATES = [
        'EUR' => 1,
        'USD' => 1.1497,
        'JPY' => 129.53,
    ];

    // used with sprintf
    protected const CURRENCY_PRECISION = [
        'EUR' => '%0.2f',
        'USD' => '%0.2f',
        'JPY' => '%0.0f',
    ];

    // used with ceil
    protected const CURRENCY_PRECISION_POW = [
        'EUR' => 100,
        'USD' => 100,
        'JPY' => 1,
    ];

    // fee for cash_in operation type in percents
    protected const CASH_IN_FEE = [
        'natural' =>  0.03,
        'legal' => 0.03,
    ];

    // max fee for cash_in operation type in EUR
    protected const MAX_CASH_IN_FEE = [
        'natural' =>  5,
        'legal' => 5,
    ];

    // fee for cash_out operation type in percents
    protected const CASH_OUT_FEE = [
        'natural' =>  0.3,
        'legal' => 0.3,
    ];

    // min fee for cash_out operation type in EUR
    protected const MIN_CASH_OUT_FEE = [
        'natural' => 0,
        'legal' => 0.5,
    ];

    // weekly amount free of charge for cash_out operation type in EUR
    protected const WEEKLY_AMOUNT_FREE_OF_CHARGE = [
        'natural' => 1000,
        'legal' => 0,
    ];

    // weekly number of operations  free of charge
    protected const OP_NUMBER_FREE_OF_CHARGE = [
        'natural' => 3,
        'legal' => 0,
    ];

    // calculated fees
    protected $final_fees = [];
    
    // temp array for users fees
    protected $user_fees = [];
    
    // input .csv file
    protected $csv_file = '';

    /**
     * @param string $file_name
     * @throws Exception
     */
    public function __construct($file_name)
    {
        if (!is_readable($file_name)) {
            throw new \Exception('The provided file is not readable!');
        }
        $this->csv_file = $file_name;
    }

    /**
     * public function for calculating fees
     * @return iterable, array with calculated fees
     * @throws ErrorException
     */
    public function calcFees() : iterable
    {
        $data = self::parseCSV();

        foreach ($data as $operation) {
            if (list($date, $user_id, $user_type, $operation_type, $amount, $currency) = $operation) {
                switch ($operation_type) {
                    case 'cash_in':
                        $this->calcCashIn($user_type, (float) $amount, $currency);
                        break;

                    case 'cash_out':
                        $this->calcCashOut($date, (int) $user_id, $user_type, (float) $amount, $currency);
                        break;

                    default:
                        throw new \ErrorException('Not a valid operation type!');
                        break;
                }
            } else {
                // this will never be reached, but no warnings will be thrown if $operation is not a valid row
                throw new \Exception('Not a valid operation!');
            }
        }

        return $this->final_fees;
    }

    /**
     * parse .csv file into array
     * @return iterable, array with parsed csv data
     */
    private function parseCSV() : iterable
    {
        $csv = array_map('str_getcsv', file($this->csv_file));

        return $csv;
    }

    /**
     * calculating cash_in fee
     * the calculated value is added in the temp array $this->final_fees
     *
     * @param string $user_type natural / legal
     * @param float $amount of current operation
     * @param string $currency
     */
    private function calcCashIn(string $user_type, float $amount, string $currency) : void
    {
        $fee = ceil($amount * self::CASH_IN_FEE[$user_type]) / 100;
        $fee_in_eur = $this->convertToEur($fee, $currency);

        $max_cash_in_fee = $this->convertToCurrency(self::MAX_CASH_IN_FEE[$user_type], $currency);
        
        $final_fee = $fee_in_eur < self::MAX_CASH_IN_FEE[$user_type] ? $fee : $max_cash_in_fee;
        
        $this->final_fees[] = $this->addPrecision($final_fee, $currency);
    }

    /**
     * calculating cash_out fee
     * the calculated value is added in the temp array $this->final_fees
     *
     * @param string $date_str in Y-m-d format
     * @param int $user_id
     * @param string $user_type natural / legal
     * @param float $amount of current operation
     * @param string $currency
     */
    private function calcCashOut(string $date_str, int $user_id, string $user_type, float $amount, string $currency)
    {
        $date = new \DateTime($date_str);
        //ISO-8601 week-numbering year + ISO-8601 week number of year
        $week = $date->format("oW");

        $amount_in_eur = $this->convertToEur($amount, $currency);

        $total_amount_in_eur = ($this->user_fees[$user_id][$week]['amount'] ?? 0) + $amount_in_eur;

        // if user has less than OP_NUMBER_FREE_OF_CHARGE (cash_out) operations and
        // total amount of current week operations is more than WEEKLY_AMOUNT_FREE_OF_CHARGE
        if (($this->user_fees[$user_id][$week]['count'] ?? 0) <= self::OP_NUMBER_FREE_OF_CHARGE[$user_type]
            && $total_amount_in_eur > self::WEEKLY_AMOUNT_FREE_OF_CHARGE[$user_type]) {
            // calc the amount to be charged in eur
            $amount_to_charge_in_eur = min(
                $total_amount_in_eur - self::WEEKLY_AMOUNT_FREE_OF_CHARGE[$user_type],
                $amount_in_eur
            );

            // calc the amount to be charged in current currency
            $amount_to_charge = $this->convertToCurrency($amount_to_charge_in_eur, $currency);
            
            // calc the min cash_out fee in current currency
            $min_cash_out_fee = $this->convertToCurrency(self::MIN_CASH_OUT_FEE[$user_type], $currency);
            
            // calc the biggest fee
            $fee = ceil($amount_to_charge * self::CASH_OUT_FEE[$user_type]) / 100;
            
            $fee_in_eur = $this->convertToEur($fee, $currency);

            $final_fee = $fee_in_eur > self::MIN_CASH_OUT_FEE[$user_type] ? $fee : $min_cash_out_fee;
            $final_fee = $this->addPrecision($final_fee, $currency);
        } else {
            $final_fee = $this->addPrecision(0, $currency);
        }

        $this->final_fees[] = $final_fee;

        $this->increaseUserAmount($user_id, $week, $amount_in_eur);
    }

    /**
     * increasing values in $this->user_fees
     *
     * @param int $user_id
     * @param string $week in oW format
     * @param float $amount_in_eur of current operation
     *
     * @return void
     */
    private function increaseUserAmount(int $user_id, string $week, float $amount_in_eur) : void
    {
        $this->user_fees[$user_id][$week]['count'] = ($this->user_fees[$user_id][$week]['count'] ?? 0) + 1;
        $this->user_fees[$user_id][$week]['amount'] =
            ($this->user_fees[$user_id][$week]['amount'] ?? 0) +
            $amount_in_eur;
    }

    /**
     * convert amount from $currency to EUR
     *
     * @param float $amount
     * @param string $currency
     *
     * @return float
     */
    private function convertToEur(float $amount, string $currency) : float
    {
        return $amount / self::CONVERSION_RATES[$currency];
    }

    /**
     * convert amount from EUR to $currency
     *
     * @param float $amount
     * @param string $currency
     *
     * @return float
     */
    private function convertToCurrency(float $amount, string $currency) : float
    {
        return $amount * self::CONVERSION_RATES[$currency];
    }
    
    /**
     * adding precision depends on smallest $currency item
     *
     * @param float $amount
     * @param string $currency
     *
     * @return string
     */
    private function addPrecision(float $amount, string $currency) : string
    {
        $amount = ceil($amount * self::CURRENCY_PRECISION_POW[$currency]) / self::CURRENCY_PRECISION_POW[$currency];
        return sprintf(self::CURRENCY_PRECISION[$currency], $amount);
    }
}
