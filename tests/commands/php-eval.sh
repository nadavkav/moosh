#!/bin/bash
source functions.sh

cd $MOODLEDIR

if $MOOSHCMD php-eval "echo 'test php eval'" | grep "tesst php eval"; then
  exit 0 
else
  exit 1
fi

