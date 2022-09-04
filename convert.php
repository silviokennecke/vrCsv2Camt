#!/usr/bin/env php
<?php declare(strict_types = 1);

$opts = getopt('i:o:h');

$input = $opts['i'] ?? null;
$output = $opts['o'] ?? null;

function usage(): void
{
    echo <<<USAGE
Usage: convert.php -i INPUT_FILE [-o OUTPUT_FILE]

Arguments:
Flag    Value       Description
-i      INPUT_FILE  The input CSV file to convert
-o      OUTPUT_FILE The file where the output should be stored in.
                    If not given, the converted file will be printed.
-h      -           Print this help
USAGE;

    exit;
}

// input validation

if (!array_key_exists('i', $opts) || array_key_exists('h', $opts)) {
    usage();
}

if (!file_exists((string) $input) || !is_readable((string) $input)) {
    die('Input file does not exist or is not readable');
}

if (
    !empty($output)
    && (
        (file_exists((string) $output) && !is_writable((string) $output))
        || (
            !file_exists((string) $output)
            && (!file_exists(dirname((string) $output)) || !is_writeable(dirname((string) $output)))
        )
    )
) {
    die('Output file is not writable');
}

// read input file

$fIn = fopen($input, 'r');
$fOut = empty($output) ? STDOUT : fopen($output, 'w');

$header = fgetcsv($fIn, null, ';');
if (!$header) {
    die('No rows existent in input file');
}

fwrite($fOut, <<<HEADER
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02 camt.053.001.02.xsd">
    <BkToCstmrStmt>
HEADER
);

while ($row = fgetcsv($fIn, null, ';')) {
	$row = array_combine($header, $row);

    $statement = <<<STATEMENT
        <Stmt>
			<Ntry>
				<Amt Ccy="%s">%.2F</Amt>
				<CdtDbtInd>%s</CdtDbtInd>
				<BookgDt>
					<Dt>%s</Dt>
				</BookgDt>
				<NtryDtls>
					<TxDtls>
						<RltdPties>
							<Dbtr>
								<Nm>%s</Nm>
							</Dbtr>
							<!--<DbtrAcct>
								<Id>
									<IBAN>%s</IBAN>
								</Id>
							</DbtrAcct>-->
							<Cdtr>
								<Nm>%s</Nm>
							</Cdtr>
							<!--<CdtrAcct>
								<Id>
									<IBAN>%s</IBAN>
								</Id>
							</CdtrAcct>-->
						</RltdPties>
						<RmtInf>
							<Ustrd>%s</Ustrd>
						</RmtInf>
					</TxDtls>
				</NtryDtls>
			</Ntry>
		</Stmt>
STATEMENT;

    $currency = $row['Waehrung'] ?? 'EUR';
    $amount = (float) str_replace(',', '.', $row['Betrag'] ?? '0');
    $absAmount = abs($amount);
    $type = $amount < 0 ? 'DBIT' : 'CRDT';
    $date = date('Y-m-d', strtotime($row['Buchungstag'] ?? '01.01.1900'));
    $reference = $row['Verwendungszweck'] ?? '';
    $creditorName = $type === 'CRDT' ? '' : ($row['Name Zahlungsbeteiligter'] ?? '');
    $creditorIban = $type === 'CRDT' ? ($row['IBAN Auftragskonto'] ?? '') : ($row['IBAN Zahlungsbeteiligter'] ?? '');
    $debtorName = $type === 'DBIT' ? '' : ($row['Name Zahlungsbeteiligter'] ?? '');
    $debtorIban = $type === 'DBIT' ? ($row['IBAN Auftragskonto'] ?? '') : ($row['IBAN Zahlungsbeteiligter'] ?? '');

    fwrite($fOut, sprintf(
        $statement,
        str_replace('&', '&amp;', $currency),
        $absAmount,
        str_replace('&', '&amp;', $type),
        str_replace('&', '&amp;', $date),
        str_replace('&', '&amp;', empty($debtorName) ? 'not provided' : $debtorName),
        str_replace('&', '&amp;', $debtorIban),
        str_replace('&', '&amp;', empty($creditorName) ? 'not provided' : $creditorName),
        str_replace('&', '&amp;', $creditorIban),
        str_replace('&', '&amp;', $reference)
    ));
}

fwrite($fOut, <<<HEADER
    </BkToCstmrStmt>
</Document>
HEADER
);

fclose($fOut);
fclose($fIn);

echo 'CSV file successfully converted';