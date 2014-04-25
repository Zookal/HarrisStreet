#!/bin/bash
: '
for adding the packages.json file to you composer.json file as a zip file to improve performance

your composer.json will like that:

    "repositories": [
        {
            "type": "composer",
            "url": "zip://firegento.zip#firegento/"
        },

'
rm -Rf firegento
curl -LO http://packages.firegento.com/packages.json
mkdir firegento
mv packages.json firegento/
rm -f firegento.zip
zip firegento firegento/*
rm -Rf firegento
