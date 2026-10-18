#!/usr/bin/env bash
# PHPUnit before 10 reads data providers from @dataProvider doc-comments and
# ignores the #[DataProvider] attributes the suite uses. Rewrite the attributes
# in tests/ so those versions run every data set. Run it on a CI checkout only:
# it changes the test files in place.
set -euo pipefail

cd "$(dirname "$0")/../.."

grep -rl '#\[DataProvider' tests | xargs perl -0pi -e 's/#\[DataProvider\(\x27(\w+)\x27\)\]/\/** \@dataProvider $1 *\//g'
