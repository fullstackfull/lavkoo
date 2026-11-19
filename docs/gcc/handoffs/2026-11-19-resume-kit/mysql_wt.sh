#!/bin/bash
# usage: mysql_wt.sh <worktree name under wt/> <database> <output file> [DB_STRICT_TESTS=1] -- <phpunit paths...> : one MySQL run on a FRESH database and a copy of the worktree; drops both afterwards
# the scratch server of the old container had an empty root password: export DB_PASSWORD yourself if yours has one
SP=/tmp/claude-0/-home-user/53272e27-a6c8-5c39-bbc4-fcca8e45650f/scratchpad
name=$1; db=$2; out=$(realpath -m "$3"); shift 3
strict=0
if [ "$1" = "STRICT" ]; then strict=1; shift; fi
wt=$SP/wt/$name
D=/tmp/mysqlwt-$name-$db
mysql -h127.0.0.1 -P3308 -uroot -e "DROP DATABASE IF EXISTS $db; CREATE DATABASE $db"
: > $out
rm -rf $D; mkdir -p $D
tar -C $wt --exclude=./vendor --exclude=./.git -cf - . | tar -C $D -xf -
cp -al $wt/vendor $D/vendor
cd $D
export APP_KEY="base64:$(openssl rand -base64 32)" APP_DEBUG=true
if [ $strict = 1 ]; then export DB_STRICT_TESTS=1; fi
env DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3308 DB_USERNAME=root DB_DATABASE=$db EXPECT_DB_DRIVER=mysql php vendor/bin/phpunit -c phpunit.mysql.xml --no-progress "$@" >> $out 2>&1
echo "exit=$?" >> $out
cd /
rm -rf $D
mysql -h127.0.0.1 -P3308 -uroot -e "DROP DATABASE IF EXISTS $db"
echo FINISHED >> $out
