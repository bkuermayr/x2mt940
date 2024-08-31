<?php

class amazonPayExternal {

	private $mt940param;
	private	$data;
	private $inFile;
	private $ppHeader;
	private $amountTotal;
	private $dataPos;
	private $dataCount;
	private $ppcodes;
	private $mapping;
	
	public function __construct($fileName) {

		include './intern/config.php';

		// initialize variables
		$this->mt940param = $amazon;
		$this->data = [];
		$this->amountTotal = 0;
		$this->dataPos = 0;
		$this->dataCount = 0;
		
		$this->infile = new myfile($fileName);

		if (file_exists("./intern/mapping/".$mapping_prefix."amazonpayExternal.json")) {
			$mapping = new myfile("./intern/mapping/".$mapping_prefix."amazonpayExternal.json","readfull");
		} else {
			$mapping = new myfile("./intern/mapping/amazonpayExternal.json","readfull");
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
		
		while (($row = $this->infile->readCSV(',')) !== false) {
			echo "<pre>Row Import Data: " . json_encode($row, JSON_PRETTY_PRINT) . "</pre>";

			$rowdata = [];
			$rowdata = array_combine($this->ppHeader,$row);
			if ($this->mt940param['startdate'] == null) {
				$this->mt940param['startdate']	= $rowdata[$this->mapping['TRANSACTION_DATE']];
			}
			$rowdata[$this->mapping['TRANSACTION_AMOUNT']] = str_replace(".","",$rowdata[$this->mapping['TRANSACTION_AMOUNT']]);
			$rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']] = str_replace(".","",$rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']]);

			$rowdata[$this->mapping['TRANSACTION_AMOUNT']] = str_replace(",",".",$rowdata[$this->mapping['TRANSACTION_AMOUNT']]);
			$rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']] = str_replace(",",".",$rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']]);

			if (! in_array($rowdata[$this->mapping['TRANSACTION_EVENTCODE']], $this->mapping['CHECK_EXCLUDECODE'])) {

				if ($rowdata[$this->mapping['TRANSACTION_AMOUNT']] > 0) {
					$transactionType = "C";
					$transactionChargeType = "D";
					$this->amountTotal += $rowdata[$this->mapping['TRANSACTION_AMOUNT']];
					$this->amountTotal += $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']];

					$rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']] = abs($rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']]);
				} else {
					$transactionType = "D";
					$transactionChargeType = "C";
					$rowdata[$this->mapping['TRANSACTION_AMOUNT']] = abs($rowdata[$this->mapping['TRANSACTION_AMOUNT']]);
					$rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']] = abs($rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']]);

					$this->amountTotal -= $rowdata[$this->mapping['TRANSACTION_AMOUNT']];
					$this->amountTotal -= $rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']];
				}
			
				$name = strtoupper(preg_replace( '/[^a-z0-9 ]/i', '_', $rowdata[$this->mapping['TRANSACTION_SELLER_NAME']]));

				$fromDate = date("Y-m-d",strtotime($rowdata[$this->mapping['ORDER_DATE']]));
				$toDate = date("Y-m-d",time());
				
				if ($rowdata[$this->mapping['TRANSACTION_EVENTCODE']] == $this->mapping['CHECK_CANCEL_PAYMENT']) {
					$ppid = $rowdata[$this->mapping['TRANSACTION_ORIGINAL_CODE']];
				} else {
					$ppid = $rowdata[$this->mapping['TRANSACTION_CODE']];
				}
				
				$invoiceData = $this->wwsInvoices->getInvoiceData($ppid, $fromDate, $toDate, $this->mt940param['fromCustomer'], $this->mt940param['toCustomer']);
				
				$defaultInvoice = 'NONREF';
				$defaultCustomer = 'AMAZON_CUSTOMER';
				$invoiceStr = 'NONREF';
			
				$spacePos = strpos($rowdata[$this->mapping['TRANSACTION_EVENTCODE']]," ",5);
				if (! $spacePos) { $spacePos = 30; }
				$event = substr($rowdata[$this->mapping['TRANSACTION_EVENTCODE']],0,$spacePos);
				if ($event == $this->mapping['TRANSACTION_EVENTCODE']) {
					$event .= " PAYOUT";
				}

			
				$mt940 = [];
				
//				if (($rowdata[$this->mapping['TRANSACTION_AMOUNT']] <> 0) and ($rowdata[$this->mapping['TRANSACTION_EVENTCODE']] == $this->mapping["CHECK_FINISH_STAT"])) {
				if ($rowdata[$this->mapping['TRANSACTION_AMOUNT']] <> 0) {
					echo "<pre>Mapping Transaction amount not zero: " . json_encode($rowdata, JSON_PRETTY_PRINT) . "</pre>";
					
					$mt940 = [
						'PAYMENT_DATE' => date("ymd",strtotime($rowdata[$this->mapping['TRANSACTION_DATE']])),
						'PAYMENT_TYPE' => $transactionType,
						'PAYMENT_AMOUNT' => str_replace(".",",",sprintf("%01.2f",$rowdata[$this->mapping['TRANSACTION_AMOUNT']])),
						'PAYMENT_NDDT' => $defaultInvoice,
						'PAYMENT_TEXT00' => 'AMAZON',
						'PAYMENT_TEXT20' => 'AMAZON '.$defaultCustomer,
						'PAYMENT_TEXT21' => $invoiceStr,
						'PAYMENT_TEXT22' => $rowdata[$this->mapping['TRANSACTION_CODE']],
						'PAYMENT_TEXT23' => $event." ".strtoupper($name),
						'PAYMENT_CODE' => $event,
						'CHARGE_DATE' => date("ymd",strtotime($rowdata[$this->mapping['TRANSACTION_DATE']])),
						'CHARGE_TYPE' => $transactionChargeType,
						'CHARGE_AMOUNT' => str_replace(".",",",sprintf("%01.2f",$rowdata[$this->mapping['TRANSACTION_CHARGEAMOUNT']])),
						'CHARGE_NDDT' => 'NONREF',
						'CHARGE_TEXT00' => 'AMAZON',
						'CHARGE_TEXT20' => 'AMAZON GEB.',
						'CHARGE_TEXT21' => $rowdata[$this->mapping['TRANSACTION_CODE']],
						'CHARGE_TEXT22' => strtoupper($name),
						'PAYMENT_STATE' =>  'S'
					];
				} 
				
				$this->data[] = $mt940;
				
				$this->dataCount++;
			}

			$this->mt940param['enddate'] = $rowdata[$this->mapping['TRANSACTION_DATE']];

		}	
		
		if (($this->mt940param['payout']) ) {
			$this->createPayoutData($this->amountTotal, $this->mt940param['enddate']);
		}
		
		if ($this->amountTotal < 0) {
			$SH = "D";
			$this->amountTotal = $this->amountTotal * (-1);
		} else {
			$SH = "C";
			$this->amountTotal = $this->amountTotal;
		}
		 $this->mt940param["TotalAmount"] = $this->amountTotal;
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
	
	private function createPayoutData($sumOfDay,$payoutdate) {
		if ($sumOfDay < 0) {
			$sumOfDay = abs($sumOfDay);
			$type = 'D';
		} else {
			$type = 'C';
		}
		
		$mt940 = [
				'PAYMENT_DATE' => date("ymd",strtotime($payoutdate)),
				'PAYMENT_TYPE' => $type,
				'PAYMENT_AMOUNT' => str_replace(".",",",sprintf("%01.2f",$sumOfDay)),
				'PAYMENT_NDDT' => '',
				'PAYMENT_TEXT00' => 'AMAZON',
				'PAYMENT_TEXT20' => 'AMAZON PAYOUT '.$payoutdate,
				'PAYMENT_TEXT21' => '',
				'PAYMENT_TEXT22' => '',
				'PAYMENT_TEXT23' => '',
				'PAYMENT_CODE' => 'PayOut',
				'CHARGE_DATE' => '',
				'CHARGE_TYPE' => '',
				'CHARGE_AMOUNT' => '',
				'CHARGE_NDDT' => '',
				'CHARGE_TEXT00' => '',
				'CHARGE_TEXT20' => '',
				'CHARGE_TEXT21' => '',
				'CHARGE_TEXT22' => '',
				'PAYMENT_STATE' =>  'S'
		];
		$this->data[] = $mt940;
	}
	
}

?>