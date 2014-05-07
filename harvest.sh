#!/bin/bash

if [ "$#" -gt 0 ] && [ "$1" == "heidelberg" -o "$1" == "e-rara" -o "$1" == "e-manuscripta" ] 
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
	harvester_exit=$?

	if [ "$harvester_exit" -ne 0 ]
	then
	    echo "script terminated with exit status ${harvester_exit}"
	    # harvesting script did not terminate with exit status 0
	    # delete this harvest restore status from before for next run of this script
	    
	    if [ -e "${provider}_records.json" ] 
	    then
		rm ${provider}_records.json
	    fi
	    
	    
	    mv json/${provider}_records_${datestamp}.json ${provider}_records.json 
	    
	    if [ "$harvester_exit" -eq 2 ]
	    then
		# no new records
		echo "no new records given"
		exit 0
		
	    fi


	    echo "harvesting script did not terminate succesfully, restored state for next run"
	    exit 1
	else
	    echo "Harvester termunated succesfully"
	fi

    else
	# initial harvest
	echo "init"
	php get_records_with_urls.php -rep $provider
	harvester_exit=$?

	if [ "$harvester_exit" -ne 0 ] 
	then
	    
	    if [ -e "${provider}_records.json" ] 
	    then
		rm ${provider}_records.json
	    fi
	    
	    echo "initial harvesting script did not terminate succesfully"
	    exit 1

	else
	    echo "Harvester termunated succesfully"
	fi
	
    fi
    
    exit 0
    
else
    echo "usage: harvest.sh provider"
    echo "provider: e-rara|e-manuscripta|heidelberg"
    exit 1
    
fi