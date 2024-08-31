<?php

class amazonPayExternal {

    private $mt940param;
    private $data;
    private $inFile;
    private $ppHeader;
    private $amountTotal;
    private $dataPos;
    private $dataCount;
    private $mapping;

    public function __construct($fileName) {
        include './intern/config.php';

        // Initialize variables
        $this->mt940param = $amazon;
        $this->data = [];
        $this->amountTotal = 0;
        $this->dataPos = 0;
        $this->dataCount = 0;

        $this->infile = new myfile($fileName);

        if (file_exists("./intern/mapping/".$mapping_prefix."amazonpayExternal.json")) {
            $mapping = new myfile("./intern/mapping/".$mapping_prefix."amazonpayExternal.json", "readfull");
        } else {
            $mapping = new myfile("./intern/mapping/amazonpayExternal.json", "readfull");
        }
        $this->mapping = $mapping->readJson();

        $this->mt940param['startdate'] = null;
        $this->mt940param['enddate'] = null;

		// Read the CSV header
		$headerRead = false;
		while (($row = $this->infile->readCSV(',')) !== false) {
			if (!$headerRead) {
				// Remove BOM and quotes
				if (!empty($row[0])) {
					$row[0] = str_replace("\xEF\xBB\xBF", '', $row[0]);
					$row[0] = trim($row[0], "\"");
				}

				// Set header and print for debugging
				$this->ppHeader = $row;
				echo "<pre>Header Row: " . json_encode($this->ppHeader, JSON_PRETTY_PRINT) . "</pre>";
				$headerRead = true;
				continue;  // Continue to the next loop to read data rows
			}

			// Print each data row for debugging
			echo "<pre>Data Row: " . json_encode($row, JSON_PRETTY_PRINT) . "</pre>";
		}

		// Output the final header after setting it
		echo "<pre>Final Header: " . json_encode($this->ppHeader, JSON_PRETTY_PRINT) . "</pre>";
    }

    public function importData() {
        if (count($this->data) > 0) {
            return true;
        }

        while (($row = $this->infile->readCSV(',')) !== FALSE) {
            $rowdata = array_combine($this->ppHeader, $row);
            if ($this->mt940param['startdate'] == null) {
                $this->mt940param['startdate'] = date("ymd", strtotime($rowdata[$this->mapping['TRANSACTION_DATE']]));
            }

            $rowdata[$this->mapping['TRANSACTION_AMOUNT']] = str_replace(".", "", $rowdata[$this->mapping['TRANSACTION_AMOUNT']]);
            $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']] = str_replace(".", "", $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']]);
            $rowdata[$this->mapping['TRANSACTION_AMOUNT']] = str_replace(",", ".", $rowdata[$this->mapping['TRANSACTION_AMOUNT']]);
            $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']] = str_replace(",", ".", $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']]);

            if (!in_array($rowdata[$this->mapping['TRANSACTION_EVENTCODE']], $this->mapping['CHECK_EXCLUDECODE'])) {

                if ($rowdata[$this->mapping['TRANSACTION_AMOUNT']] > 0) {
                    $transactionType = "C";
                    $transactionChargeType = "D";
                    $this->amountTotal += $rowdata[$this->mapping['TRANSACTION_AMOUNT']];
                    $this->amountTotal += $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']];
                } else {
                    $transactionType = "D";
                    $transactionChargeType = "C";
                    $rowdata[$this->mapping['TRANSACTION_AMOUNT']] = abs($rowdata[$this->mapping['TRANSACTION_AMOUNT']]);
                    $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']] = abs($rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']]);
                    $this->amountTotal -= $rowdata[$this->mapping['TRANSACTION_AMOUNT']];
                    $this->amountTotal -= $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']];
                }

                $name = strtoupper(preg_replace('/[^a-z0-9 ]/i', '_', $rowdata[$this->mapping['TRANSACTION_SELLER_NAME']]));
                $event = substr($rowdata[$this->mapping['TRANSACTION_EVENTCODE']], 0, 30);
                if ($event == $this->mapping['TRANSACTION_EVENTCODE']) {
                    $event .= " PAYOUT";
                }

                if ($rowdata[$this->mapping['TRANSACTION_AMOUNT']] != 0) {
                    $mt940 = [
                        'PAYMENT_DATE' => date("ymd", strtotime($rowdata[$this->mapping['TRANSACTION_DATE']])),
                        'PAYMENT_TYPE' => $transactionType,
                        'PAYMENT_AMOUNT' => str_replace(".", ",", sprintf("%01.2f", $rowdata[$this->mapping['TRANSACTION_AMOUNT']])),
                        'PAYMENT_TEXT00' => 'AMAZON',
                        'PAYMENT_TEXT20' => 'AMAZON ' . $name,
                        'PAYMENT_TEXT21' => '',
                        'PAYMENT_TEXT22' => $rowdata[$this->mapping['TRANSACTION_CODE']],
                        'PAYMENT_TEXT23' => $event . " " . strtoupper($name),
                        'PAYMENT_CODE' => $event,
                        'CHARGE_DATE' => date("ymd", strtotime($rowdata[$this->mapping['TRANSACTION_DATE']])),
                        'CHARGE_TYPE' => $transactionChargeType,
                        'CHARGE_AMOUNT' => str_replace(".", ",", sprintf("%01.2f", $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']])),
                        'CHARGE_TEXT00' => 'AMAZON',
                        'CHARGE_TEXT20' => 'AMAZON GEB.',
                        'CHARGE_TEXT21' => $rowdata[$this->mapping['TRANSACTION_CODE']],
                        'CHARGE_TEXT22' => strtoupper($name),
                        'PAYMENT_STATE' => 'S'
                    ];

                    $this->data[] = $mt940;
                    $this->dataCount++;
                }
            }

            $this->mt940param['enddate'] = date("ymd", strtotime($rowdata[$this->mapping['TRANSACTION_DATE']]));
        }

        $this->mt940param['endbalance'] = number_format($this->amountTotal, 2, ',', '');

        if ($this->amountTotal < 0) {
            $SH = "D";
            $this->amountTotal = abs($this->amountTotal);
        } else {
            $SH = "C";
        }
        $this->mt940param["TotalAmount"] = number_format($this->amountTotal, 2, ',', '');
        $this->mt940param["TotalSH"] = $SH;
    }

    public function getAllData() {
        return $this->data;
    }

    public function getNext() {
        if ($this->dataPos < $this->dataCount) {
            return $this->data[$this->dataPos++];
        } else {
            return false;
        }
    }

    public function getParameter() {
        return $this->mt940param;
    }

    public function generateMT940() {
        $mt940 = [];

        // Set initial balance
        $mt940[] = ":20:" . "AMZ" . date("ymdHis");
        $mt940[] = ":25:" . $this->mt940param['blz'] . "/" . $this->mt940param['konto'];
        $mt940[] = ":28C:00000";
        $mt940[] = ":60F:" . $this->mt940param['TotalSH'] . date("ymd") . $this->mt940param['currency'] . "0,00";

        foreach ($this->data as $transaction) {
            $mt940[] = ":61:" . $transaction['PAYMENT_DATE'] . $transaction['PAYMENT_TYPE'] . $transaction['PAYMENT_AMOUNT'] . "NMSC" . $transaction['PAYMENT_CODE'];
            $mt940[] = ":86:" . $transaction['PAYMENT_TEXT00'] . " " . $transaction['PAYMENT_TEXT20'] . " " . $transaction['PAYMENT_TEXT22'];
        }

        $mt940[] = ":62F:" . $this->mt940param['TotalSH'] . date("ymd") . $this->mt940param['currency'] . $this->mt940param['TotalAmount'];

        return implode("\r\n", $mt940);
    }
}
?>
