#!/bin/bash

# Change this to YOUR email
TOEMAIL="knives.dev@gmail.com"
# Change this to what email it should be sent from, you should at least have a spf setup on ur domain for server etc..
FROMEMAIL="crash@florp.us"
STOP=0

trap ctrl_c INT
function ctrl_c() {
	echo "Got CTRL-C Killing job"
        kill -INT %1
	STOP=1
}

while true; do
	OUTFILE=$(uuidgen)
	echo "Saving to $OUTFILE"
	$@ |& tee "$OUTFILE" &
	wait $!
	RC=${PIPESTATUS[0]}
#	RCA=$?
#	RCB=${PIPESTATUS[1]}
#	echo "RC $RC RCA $RCA RCB $RCB"
	# 130 meant sigint or sigterm
	# [ $RC -eq 0 ] || [ $RC -eq 130 ] ||
	# ONLY USING STOP VAR HERE, BECAUSE AMPHP OR PHP IS A HUGE JACKASS AND RETURNING WITH 0 ON FATAL ERROR
	# CAN ONLY STOP RESTARTS WITH A CTRL-C
	if [ $STOP -eq 1 ]; then
		echo "STOP=$STOP | Exited with code ${RC}, not restarting"
		rm "$OUTFILE"
		break
	fi
	gzip "$OUTFILE"
	OUTFILE="${OUTFILE}.gz"
	# some reason thunderbird wont show these message bodies
	echo "$* has crashed with exit ${RC} attached are logs" | mail -A "$OUTFILE" -r $FROMEMAIL -s "Script crash report $*" $TOEMAIL
	rm "$OUTFILE"
        echo "Error Code $RC Restarting..."
done
