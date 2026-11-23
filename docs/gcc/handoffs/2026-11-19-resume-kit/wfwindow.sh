#!/usr/bin/env bash
# usage: wfwindow.sh <seconds> : prints new journal lines of the tracked workflows for up to <seconds>
cd /root/.claude/projects/-home-user/53272e27-a6c8-5c39-bbc4-fcca8e45650f/subagents/workflows || exit 1
declare -A base
for w in wf_f72f3ab1-187 wf_ca694560-af6 wf_0e83c49d-626; do base[$w]=$(grep -c '' $w/journal.jsonl); done
end=$((SECONDS+$1))
while [ $SECONDS -lt $end ]; do
  for w in "${!base[@]}"; do
    n=$(grep -c '' $w/journal.jsonl)
    if [ "$n" -gt "${base[$w]}" ]; then
      echo "NEW in $w ($n lines)"
      tail -n $((n-${base[$w]})) $w/journal.jsonl | python3 -c "
import sys, json
for l in sys.stdin:
    e=json.loads(l); r=e.get('result'); tag=''
    if isinstance(r,dict): tag=str(r.get('verdict') or r.get('task') or r.get('part') or r.get('unit') or r.get('final_decision') or '')[:150]
    print(' ', e.get('type'), '|', (e.get('label') or '')[:55], '|', tag)
"
      base[$w]=$n
    fi
  done
  sleep 20
done
echo "window over"
