#!/bin/bash
dir=$(dirname "$1")
reltarget=$(readlink "$1")
if [[ $reltarget == /* ]]; then
    abstarget=$reltarget
else
    abstarget=$dir/$reltarget
fi

rm -f "$1"
cp -af "$abstarget" "$1" || {
    # on failure, restore the symlink
    rm -rfv "$1"
    ln -sfv "$reltarget" "$1"
}
