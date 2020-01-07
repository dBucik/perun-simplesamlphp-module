<?php

/**
 * Script for updating UES in separate thread
 *
 * @author Pavel Vyskočil <vyskocilpavel@muni.cz>
 */

use SimpleSAML\Logger;
use SimpleSAML\Module\perun\Adapter;

$adapter = Adapter::getInstance(Adapter::RPC);

$entityBody = file_get_contents('php://input');
$body = json_decode($entityBody, true);

$attributes = $body['attributes'];
$attrMap = $body['attrMap'];
$attrsToConversion = $body['attrsToConversion'];
$perunUserId = $body['perunUserId'];

const UES_ATTR_NMS = 'urn:perun:ues:attribute-def:def:';

try {
    $userExtSource = $adapter->getUserExtSource(
        $attributes['sourceIdPEntityID'][0],
        $attributes['sourceIdPEppn'][0]
    );
    if ($userExtSource === null) {
        throw new Exception(
            'sspmod_perun_Auth_Process_UpdateUserExtSource: there is no UserExtSource with ExtSource ' .
            $attributes['sourceIdPEntityID'][0] . " and Login " .
            $attributes['sourceIdPEppn'][0]
        );
    }

    $attributes = $adapter->getUserExtSourceAttributes($userExtSource['id'], array_keys($attrMap));

    if ($attributes === null) {
        throw new Exception(
            'sspmod_perun_Auth_Process_UpdateUserExtSource: getting attributes was not successful.'
        );
    }

    $attributesToUpdate = [];
    foreach ($attributes as $attribute) {
        $attrName = UES_ATTR_NMS . $attribute['friendlyName'];
        if (isset($attrMap[$attrName], $attributes[$attrMap[$attrName]])) {
            $attr = $attributes[$attrMap[$attrName]];

            if (in_array(UES_ATTR_NMS . $attribute['friendlyName'], $attrsToConversion)) {
                $arrayAsString = [''];
                foreach ($attr as $value) {
                    $arrayAsString[0] .= $value . ';';
                }
                if (!empty($arrayAsString[0])) {
                    $arrayAsString[0] = substr($arrayAsString[0], 0, -1);
                }
                $attr = $arrayAsString;
            }

            if (strpos($attribute['type'], 'String') ||
                strpos($attribute['type'], 'Integer') ||
                strpos($attribute['type'], 'Boolean')) {
                $valueFromIdP = $attr[0];
            } elseif (strpos($attribute['type'], 'Array') || strpos($attribute['type'], 'Map')) {
                $valueFromIdP = $attr;
            } else {
                throw new Exception(
                    'sspmod_perun_updateUes: unsupported type of attribute.'
                );
            }
            if ($valueFromIdP !== $attribute['value']) {
                $attribute['value'] = $valueFromIdP;
                array_push($attributesToUpdate, $attribute);
            }
        }
    }

    if (!empty($attributesToUpdate)) {
        $adapter->setUserExtSourceAttributes($userExtSource['id'], $attributesToUpdate);
    }

    $adapter->updateUserExtSourceLastAccess($userExtSource['id']);

    Logger::debug('sspmod_perun_updateUes - Updating UES for user with userId: ' . $perunUserId . ' was successful.');
} catch (\Exception $ex) {
    Logger::warning(
        'sspmod_perun_updateUes: Updating UES for user with userId: ' . $perunUserId . ' was not successful: ' .
        $ex->getMessage()
    );
}