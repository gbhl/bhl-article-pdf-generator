#!/bin/bash

SUM=0
COUNT=0
TOTAL=$(ls -1 ./output | wc -l)
SIZE=$(du -skh ./output | cut -f 1 -d ' ')

for FILE in ./output/*.pdf; do
   LINES=$(strings < $FILE | sed -n 's|.*/Count -\{0,1\}\([0-9]\{1,\}\).*|\1|p' | sort -rn | head -n 1)
   SUM=$(($SUM+$LINES))
   COUNT=$(($COUNT+1))
   echo -en "\rReading $COUNT/$TOTAL..."
done
echo
echo "Number of Files: $COUNT"
echo "Total Lines: $SUM"
echo "Total Size: $SIZE"
