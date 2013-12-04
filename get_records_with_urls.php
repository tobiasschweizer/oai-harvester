<?php
/**
 * Author: Tobias Schweizer, Digital Humanities Lab, University of Basel,
 * Contact: t.schweizer@unibas.ch
 *
 * Version: 0.9
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

function print_usage($out) {
    fwrite($out, 'Usage: get_records_with_urls.php e-rara|e-manuscripta [yyyy-mm-ddThh:mm:ssZ]' . PHP_EOL);
    fwrite($out, 'Limit request by specifying a datestamp (records created modified >= specified datestamp)' . PHP_EOL);
}


$record_array = array();

if ($argc < 2) {
    print_usage(STDERR);
    exit(1);
}

if ($argv[1] == 'e-rara') {
    $provider = 'e-rara';
    $base_url = 'http://www.e-rara.ch';
    $oai_frag = '/oai';
} elseif ($argv[1] == 'e-manuscripta') {
    $provider = 'e-manuscripta';
    $base_url = 'http://www.e-manuscripta.ch';
    $oai_frag = '/oai/';
} elseif ($argv[1] == 'heidi') {
    $provider = 'heidi';
    $base_url = 'http://digi.ub.uni-heidelberg.de';
    $oai_frag = '/cgi-bin/digioai.cgi/';

} else {
    print_usage(STDERR);
    exit(1);
}

$datestr = '';
if ($argc == 3) {
    // limit request by datestamp
    $datestamp = $argv[2];

    // check $datestamp
    if (preg_match('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/', $datestamp) !== 1) {
        fwrite(STDERR, 'Datestamp format is invalid' . PHP_EOL);
        exit(1);
    }
    $datestr = '&from=' . $datestamp;
}

$conts = file_get_contents($base_url. $oai_frag . '?verb=ListRecords&metadataPrefix=oai_dc' . $datestr);

$xml = new DOMDocument();
$xml->loadXML($conts);

$date = $xml->getElementsByTagName('responseDate');
$date = $date->item(0)->textContent;

echo 'Starting Harvesting ' .PHP_EOL;
echo $date . PHP_EOL . PHP_EOL;

$token = $xml->getElementsByTagName('resumptionToken');

if (file_exists($provider . '_records.json')) {
    echo 'Existing file ' . $provider . '_records.json would be overwritten, please move it to another destination before runing this application' . PHP_EOL;
    exit(0);
}

$file_ptr = fopen($provider . '_records.json', 'w');

$counter = 1;


do {


    $rec_counter = 1;

    $records = $xml->getElementsByTagName('record');
    foreach ($records as $record) {
        // foreach record node

        foreach ($record->childNodes as $rec_node) {
            // inside a record
            if ($rec_node->nodeType == 3) continue; // text node

            if ($rec_node->nodeName == 'header') {
                // header

                foreach ($rec_node->childNodes as $header_node) {
                    if ($header_node->nodeType == 3) continue; // text node

                    if ($header_node->nodeName == 'identifier') {
                        $identifier = $header_node->nodeValue;
                    }

                    if (!isset($id)) $id = $header_node->nodeValue;

                    if (!array_key_exists($id, $record_array)) {
                        $record_array[$id] =  array();
                        $record_array[$id]['header'] =  array();
                        $record_array[$id]['metadata'] =  array();
                    }

                    if (array_key_exists($header_node->nodeName, $record_array[$id]['header'])) {
                        if (!is_array($record_array[$id]['header'][$header_node->nodeName])) {
                            $tmp = $record_array[$id]['header'][$header_node->nodeName];
                            $record_array[$id]['header'][$header_node->nodeName] = array();
                            $record_array[$id]['header'][$header_node->nodeName][] = $tmp;
                        }
                        $record_array[$id]['header'][$header_node->nodeName][] = $header_node->textContent;
                    } else {
                        $record_array[$id]['header'][$header_node->nodeName] = $header_node->textContent;
                    }
                }

            } else {
                // metadata
                foreach ($rec_node->childNodes as $metadata_node) {
                    if ($metadata_node->nodeType == 3) continue; // text node
                    foreach ($metadata_node->childNodes as $metadata_node_item) {
                        if ($metadata_node_item->nodeType == 3) continue; // text node

                        if (array_key_exists($metadata_node_item->nodeName, $record_array[$id]['metadata'])) {
                            if (!is_array($record_array[$id]['metadata'][$metadata_node_item->nodeName])) {
                                $tmp = $record_array[$id]['metadata'][$metadata_node_item->nodeName];
                                $record_array[$id]['metadata'][$metadata_node_item->nodeName] = array();
                                $record_array[$id]['metadata'][$metadata_node_item->nodeName][] = $tmp;
                            }
                            $record_array[$id]['metadata'][$metadata_node_item->nodeName][] = $metadata_node_item->textContent;
                        } else {
                            $record_array[$id]['metadata'][$metadata_node_item->nodeName] = $metadata_node_item->textContent;
                        }
                    }
                }
            }


        }


        // get file ids by using the mets metadata prefix
        $mets_conts = file_get_contents($base_url . $oai_frag . '?verb=GetRecord&metadataPrefix=mets&identifier=' . $identifier);

        
        if ($provider == 'e-rara' || $provider == 'e-manuscripta') {

	    $mets_xml = new DOMDocument();
	    $mets_xml->loadXML($mets_conts);

            $ns = $mets_xml->lookupNamespaceURI('mets');
            $fileSec = $mets_xml->getElementsByTagNameNS($ns, 'fileSec');

	    $record_array[$id]['urls'] = array();
	    $record_array[$id]['urls']['max'] = array();
	    $record_array[$id]['urls']['thumb'] = array();


            foreach ($fileSec->item(0)->childNodes as $fileGrp) {
                if ($fileGrp->nodeType == 3) continue; // text node

                if ($fileGrp->getAttribute('USE') == 'MAX') {

                    
                    foreach($fileGrp->childNodes as $file) {
                        if ($file->nodeType == 3) continue; // text node



                        $img_id = $file->getAttribute('ID');
                        $pos = strrpos($img_id, '_');

                        $img_id = substr($file->getAttribute('ID'), ($pos+1));
                        $record_array[$id]['urls']['max'][] = $base_url . '/image/view/' . $img_id;
                        $record_array[$id]['urls']['thumb'][] = $base_url . '/image/thumb/' . $img_id;




                        


                    }

                    break;
                }

            }

        } elseif ($provider == 'heidi') {

	    /*$ns_xlink = $mets_xml->lookupNamespaceURI('xlink');

	      foreach ($fileGrp->childNodes as $loc) {
	      if ($loc->nodeType == 3) continue; // text node
	      
	      $record_array[$id]['urls']['max'][] = $file->getAttributeNS($ns_xlink, 'href');
	      
	      }*/


	}

	if (count($record_array[$id]['urls']['max']) == 0 || count($record_array[$id]['urls']['thumb']) == 0) fwrite(STDERR, 'No images given for ' . $id . PHP_EOL);
	if (count($record_array[$id]['urls']['max']) != count($record_array[$id]['urls']['thumb'])) fwrite(STDERR, 'Number of max images not equals number of thumbs for ' . $id . PHP_EOL);

        echo 'Request num: ' . $counter .  ', record: ' . $rec_counter++ . PHP_EOL;
        echo $id . PHP_EOL;
	print_r($record_array[$id]);
        echo '---------------------------' . PHP_EOL;
        echo '---------------------------' . PHP_EOL;
        unset($id);


    }

    // setup new xml
    if ($token->length == 0) break; // no token anymore

    $conts = file_get_contents($base_url . $oai_frag . '?verb=ListRecords&resumptionToken=' . $token->item(0)->textContent);
    $xml = new DOMDocument();
    $xml->loadXML($conts);

    $token = $xml->getElementsByTagName('resumptionToken');

    $counter++;

} while(true);

fwrite($file_ptr, json_encode($record_array));
fclose($file_ptr);

exit(0);

?>