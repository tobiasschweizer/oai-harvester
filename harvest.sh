#!/bin/bash

if [ "$#" -gt 0 ] && [ $1 == "heidelberg" -o $1 == "e-rara" -o $1 == "e-manuscripta" ] 
then 
    # $1 contains a valid provider
    provider=$1
    
    # check for existence of a previous harvesting file for this provider
    if [ -e "${provider}_records.json" ] 
    then
	# incremental harvest
	echo "incremental harvest"
	echo "determine datestamp"
	datestamp=`jshon -e "datestamp" < ${provider}_records.json | sed "s/^\([\"']\)\(.*\)\1\$/\2/g"`
	echo $datestamp
	
	# check for dir "json" and create it if does not exist yet
	if [ ! -d "json" ]
	then
	    mkdir json
	fi

	# mv existing json-file to dir json and append datestamp to filename
	mv ${provider}_records.json json/${provider}_records_${datestamp}.json

	# start incremental harvest
	php get_records_with_urls.php -rep $provider -date $datestamp

    else
	# initial harvest
	echo "init"
	php get_records_with_urls.php -rep $provider
	
    fi
    
    exit 0
    
else
    echo "usage: harvest.sh provider"
    echo "provider: e-rara|e-manuscripta|heidelberg"
    exit 1
    
fi