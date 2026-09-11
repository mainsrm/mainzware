<?php
function norm($s){return strtolower(preg_replace('/[^a-z0-9]+/i','',$s));}
$mappedName = 'Gas';
$transDesc = 'XX6947 CHK PURCH SIG COSTCO GAS #1632 LIBERTY TOWNS OH 93163213 072920';
$transCat = 'Gas/Fuel';
$budgetCats = ['Housing','Utilities','Vehicles','Insurance','Subscriptions Memberships','Education (Tuition)','Giving','Groceries','Transportation','Dining','Household','Personal','Misc','Savings Target'];

$nMapped = norm($mappedName);
$nDesc = norm($transDesc);
$nCat = norm($transCat);

foreach ($budgetCats as $bdName) {
    $nBd = norm($bdName);
    $match = false;
    if ($nMapped === $nBd) $match = true;
    if (!$match && (strpos($nMapped, $nBd) !== false || strpos($nBd, $nMapped) !== false)) $match = true;
    if (!$match && (strpos($nDesc, $nBd) !== false || strpos($nCat, $nBd) !== false)) $match = true;
    $synonyms = [ 'gas'=>['transportation','transport','gasfuel'], 'transportation'=>['gas','gasfuel','fuel'], 'groceries'=>['grocerieskroger','grocerieswholesale','grocerieswalmart','groc'] ];
    if (!$match) {
        foreach ($synonyms as $key=>$vals) {
            if ($nMapped === $key || $nBd === $key) {
                foreach ($vals as $v) {
                    if ($nMapped === $v || $nBd === $v) { $match = true; break 3; }
                }
            }
        }
    }
    if (!$match && $nMapped === 'gas' && $nBd === 'transportation') $match = true;
    echo "bdName='$bdName' nBd='$nBd' => match=" . ($match? 'YES':'no') . "\n";
}

?>