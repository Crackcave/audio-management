#!/bin/sh

if [ "$1" = "playlist" ]
then
  echo "TEST 1"
  echo "TEST 2"
  echo "TEST 3"
  echo "Bing_Crosby_-_Tobermory_Bay.m4a"
fi
if [ "$1" = "-v" ]
then
  echo "Bing_Crosby_-_Tobermory_Bay.m4a"
	echo "[playing] #1/1   0:37/3:21 (18%)"
	echo "volume:  5%   repeat: off   random: off   single: off   consume: on"
fi
if [ "$1" = "volume" ]
then
  echo ":24%"
fi
