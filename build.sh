#!/usr/bin/env bash

# Builds the zip file that can be uploaded to Wordpress
BASEDIR=$(dirname "$0")
STARTDIR=$(pwd)
cd "$BASEDIR"
rm -rf woocommerce-paysbuy-payment-gateway.zip
cd src
zip -q -r ../woocommerce-paysbuy-payment-gateway.zip woocommerce-paysbuy-payment-gateway -x "*/\.*"
cd "$STARTDIR"

