#!/usr/bin/env bash
# The Saudi baseline pack (tests/Feature/Address/SaudiGoldenResponsesTest.php and its golden files in tests/Fixtures/SaudiGolden) pins what the Saudi store answers and writes
# with every GCC switch off. A pull request may add golden files, so that the pack grows, but it may not change or delete one that already exists: a changed expectation
# is a change of Saudi behaviour, which is the owner's decision and not a side effect of a GCC feature. A change the owner approved is made in a pull request whose title
# carries the marker below, written by a person.
#
# usage: BASE_SHA=<base commit> HEAD_SHA=<head commit> PR_TITLE=<title> saudi-baseline-guard.sh   (run inside a checkout that has both commits)
set -euo pipefail

readonly GOLDEN_DIRECTORY='tests/Fixtures/SaudiGolden'
readonly MARKER='[saudi-baseline-change]'

base="${BASE_SHA:?BASE_SHA is the commit the pull request is based on}"
head="${HEAD_SHA:-HEAD}"
title="${PR_TITLE:-}"

# Status letters other than A (added): M (modified), D (deleted), T (type changed). Renames are shown as a delete and an add, so that moving a golden file away is caught too.
changed="$(git diff --name-status --no-renames "${base}...${head}" -- "${GOLDEN_DIRECTORY}" | awk '$1 != "A" { print $1 "  " $2 }')"

if [ -z "${changed}" ]; then
  echo "The Saudi baseline expectations are unchanged: no existing golden file is modified or deleted."
  exit 0
fi

if [[ "${title}" == *"${MARKER}"* ]]; then
  echo "The Saudi baseline expectations change in this pull request, and its title carries ${MARKER}: a person decided it."
  echo "${changed}"
  exit 0
fi

echo "::error title=Saudi baseline changed::This pull request modifies or deletes an existing golden answer of the Saudi baseline pack. Saudi behaviour is not changed by a GCC feature."
echo "${changed}"
echo "If the owner approved this change, put ${MARKER} in the title of the pull request."
exit 1
