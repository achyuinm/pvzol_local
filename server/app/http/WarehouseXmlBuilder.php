<?php
declare(strict_types=1);

final class WarehouseXmlBuilder
{
    /** @var array<int,bool>|null */
    private static ?array $allSkillIds = null;
    /** @var array<int,bool>|null */
    private static ?array $specSkillIds = null;

    private static function loadSkillIdSet(bool $spec): array
    {
        if ($spec && is_array(self::$specSkillIds)) {
            return self::$specSkillIds;
        }
        if (!$spec && is_array(self::$allSkillIds)) {
            return self::$allSkillIds;
        }
        $rel = $spec ? '/../../../runtime/config/skills/spec.json' : '/../../../runtime/config/skills/all.json';
        $path = realpath(__DIR__ . $rel) ?: (__DIR__ . $rel);
        $set = [];
        if (is_file($path)) {
            $raw = file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                $arr = json_decode($raw, true);
                if (is_array($arr)) {
                    foreach ($arr as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $id = (int)($row['id'] ?? 0);
                        if ($id > 0) {
                            $set[$id] = true;
                        }
                    }
                }
            }
        }
        if ($spec) {
            self::$specSkillIds = $set;
        } else {
            self::$allSkillIds = $set;
        }
        return $set;
    }

    private static function setChildXml(\DOMElement $parent, string $childName, string $rawXml): void
    {
        $target = null;
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->tagName === $childName) {
                $target = $node;
                break;
            }
        }
        if (!$target) {
            $target = $parent->ownerDocument->createElement($childName);
            $parent->appendChild($target);
        }
        while ($target->firstChild) {
            $target->removeChild($target->firstChild);
        }
        // Clear existing attributes copied from template node.
        if ($target->hasAttributes()) {
            $toRemove = [];
            foreach ($target->attributes as $attr) {
                $toRemove[] = $attr->nodeName;
            }
            foreach ($toRemove as $attrName) {
                $target->removeAttribute($attrName);
            }
        }
        $rawXml = trim($rawXml);
        if ($rawXml === '') {
            return;
        }
        $tmp = new \DOMDocument('1.0', 'UTF-8');
        if (@$tmp->loadXML($rawXml) && $tmp->documentElement) {
            // Guard: client crashes when sk/ssk contains ids unknown to SkillManager.
            if ($tmp->documentElement->tagName === 'sk' || $tmp->documentElement->tagName === 'ssk') {
                $allow = self::loadSkillIdSet($tmp->documentElement->tagName === 'ssk');
                if ($allow !== []) {
                    $toRemove = [];
                    foreach ($tmp->documentElement->childNodes as $n) {
                        if (!$n instanceof \DOMElement || $n->tagName !== 'item') {
                            continue;
                        }
                        $sid = (int)$n->getAttribute('id');
                        if ($sid <= 0 || !isset($allow[$sid])) {
                            $toRemove[] = $n;
                        }
                    }
                    foreach ($toRemove as $n) {
                        $tmp->documentElement->removeChild($n);
                    }
                }
            }
            if ($tmp->documentElement->tagName === $childName) {
                if ($tmp->documentElement->hasAttributes()) {
                    foreach ($tmp->documentElement->attributes as $srcAttr) {
                        $target->setAttribute($srcAttr->nodeName, $srcAttr->nodeValue);
                    }
                }
                foreach ($tmp->documentElement->childNodes as $child) {
                    $target->appendChild($parent->ownerDocument->importNode($child, true));
                }
            } else {
                $target->appendChild($parent->ownerDocument->importNode($tmp->documentElement, true));
            }
        }
    }

    private static function qualityNameByLevel(int $q): string
    {
        $qq = max(1, min(18, $q));
        static $map = null;
        if (!is_array($map)) {
            $map = [];
            $path = realpath(__DIR__ . '/../../../runtime/config/quality_names.json')
                ?: (__DIR__ . '/../../../runtime/config/quality_names.json');
            if (is_file($path)) {
                $raw = file_get_contents($path);
                if (is_string($raw) && $raw !== '') {
                    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
                        $raw = substr($raw, 3);
                    }
                    $arr = json_decode($raw, true);
                    if (is_array($arr)) {
                        $map = $arr;
                    }
                }
            }
        }
        $k = (string)$qq;
        return (string)($map[$k] ?? ('Q' . $qq));
    }

    /**
     * @param array<int,array{item_id:string,qty:int}> $inventoryRows
     * @param array<string,array<string,mixed>> $itemsMap
     * @param array<int,array{org_id:int,tpl_id:int,level:int,quality:int,exp:int,hp:int}> $organismRows
     */
    public static function build(string $templateXml, array $inventoryRows, array $itemsMap = [], array $organismRows = []): string
    {
        $sx = @simplexml_load_string($templateXml);
        if ($sx === false || !isset($sx->warehouse)) {
            return $templateXml;
        }

        $toolsAgg = [];
        foreach ($inventoryRows as $row) {
            $itemId = (string)($row['item_id'] ?? '');
            $qty = (int)($row['qty'] ?? 0);
            if ($qty <= 0 || $itemId === '') {
                continue;
            }
            $id = '';
            if (str_starts_with($itemId, 'tool:')) {
                $id = trim(substr($itemId, 5));
            } elseif (preg_match('/^\d+$/', $itemId)) {
                // Compatibility: treat plain numeric IDs as tool items.
                $id = $itemId;
            }
            if ($id === '' || !preg_match('/^\d+$/', $id)) {
                continue;
            }
            $idInt = (int)$id;
            if ($idInt <= 0) {
                continue;
            }
            // Guard: skip unknown tool ids to avoid null-tool crashes in some client panels.
            if ($itemsMap !== [] && !isset($itemsMap[(string)$idInt])) {
                continue;
            }
            $prev = (int)($toolsAgg[$idInt] ?? 0);
            $sum = $prev + $qty;
            // Display-only cap for legacy client; keep real large value in DB.
            if ($sum > 999999999) {
                $sum = 999999999;
            }
            $toolsAgg[$idInt] = $sum;
        }
        ksort($toolsAgg, SORT_NUMERIC);
        $tools = [];
        foreach ($toolsAgg as $idInt => $amount) {
            if ($amount <= 0) {
                continue;
            }
            $tools[] = ['id' => (string)$idInt, 'amount' => (int)$amount];
        }

        if (isset($sx->warehouse->tools)) {
            unset($sx->warehouse->tools->item);
            foreach ($tools as $it) {
                $meta = (array)($itemsMap[$it['id']] ?? []);
                $node = $sx->warehouse->tools->addChild('item');
                $node->addAttribute('id', (string)$it['id']);
                $node->addAttribute('amount', (string)$it['amount']);
                if (isset($meta['name']) && is_string($meta['name']) && $meta['name'] !== '') {
                    $node->addAttribute('name', $meta['name']);
                }
                if (isset($meta['type']) && is_scalar($meta['type'])) {
                    $node->addAttribute('type', (string)$meta['type']);
                }
            }
        }

        // Replace organisms list with DB-driven rows so template static plants don't leak.
        // Keep the full shape by cloning the first organism template node (attributes + children).
        if (isset($sx->warehouse->organisms) && isset($sx->warehouse->organisms->item)) {
            $firstTpl = null;
            foreach ($sx->warehouse->organisms->item as $tplNode) {
                $firstTpl = $tplNode;
                break;
            }
            $parentDom = dom_import_simplexml($sx->warehouse->organisms);
            $tplDom = $firstTpl !== null ? dom_import_simplexml($firstTpl) : null;
            $doc = $parentDom ? $parentDom->ownerDocument : null;
            unset($sx->warehouse->organisms->item);
            foreach ($organismRows as $org) {
                $orgId = (int)($org['org_id'] ?? 0);
                $tplId = (int)($org['tpl_id'] ?? 151);
                if ($tplId <= 0) {
                    $tplId = 151;
                }
                $level = max(1, (int)($org['level'] ?? 1));
                $quality = max(1, (int)($org['quality'] ?? 1));
                $exp = max(0, (int)($org['exp'] ?? 0));
                $hp = max(1, (int)($org['hp'] ?? 100));
                if ($parentDom && $tplDom && $doc) {
                    $newDom = $tplDom->cloneNode(true);
                    if ($newDom instanceof \DOMElement) {
                        $newDom->setAttribute('id', (string)$orgId);
                        $newDom->setAttribute('pid', (string)$tplId);
                        $newDom->setAttribute('at', (string)((int)($org['attack'] ?? (100 + $level * 10))));
                        $newDom->setAttribute('mi', (string)((int)($org['miss'] ?? 100)));
                        $newDom->setAttribute('sp', (string)((int)($org['speed'] ?? 100)));
                        $newDom->setAttribute('pr', (string)((int)($org['precision_val'] ?? $org['precision'] ?? 100)));
                        $newDom->setAttribute('new_miss', (string)((int)($org['new_miss'] ?? 100)));
                        $newDom->setAttribute('new_precision', (string)((int)($org['new_precision'] ?? 100)));
                        $newDom->setAttribute('gr', (string)$level);
                        $newDom->setAttribute('ex', (string)$exp);
                        $newDom->setAttribute('ema', (string)(max(1, $level) * 1000));
                        $newDom->setAttribute('emi', (string)(max(0, $level - 1) * 1000));
                        $newDom->setAttribute('hp', (string)$hp);
                        $newDom->setAttribute('hm', (string)((int)($org['hp_max'] ?? $hp)));
                        $newDom->setAttribute('im', (string)$quality);
                        $newDom->setAttribute('qu', self::qualityNameByLevel((int)$quality));
                        $newDom->setAttribute('dq', (string)((int)($org['dq'] ?? 0)));
                        $newDom->setAttribute('gi', (string)((int)($org['gi'] ?? 0)));
                        $newDom->setAttribute('ma', (string)((int)($org['mature'] ?? 1)));
                        $newDom->setAttribute('ss', (string)((int)($org['ss'] ?? 0)));
                        $newDom->setAttribute('sh', (string)((int)($org['sh'] ?? 0)));
                        $newDom->setAttribute('sa', (string)((int)($org['sa'] ?? 0)));
                        $newDom->setAttribute('spr', (string)((int)($org['spr'] ?? 0)));
                        $newDom->setAttribute('sm', (string)((int)($org['sm'] ?? 0)));
                        $newDom->setAttribute('new_syn_precision', (string)((int)($org['new_syn_precision'] ?? 0)));
                        $newDom->setAttribute('new_syn_miss', (string)((int)($org['new_syn_miss'] ?? 0)));
                        $newDom->setAttribute('fight', (string)((int)($org['fight'] ?? 0)));
                        // sk/ssk are XML child nodes in client schema, not attributes.
                        self::setChildXml($newDom, 'sk', (string)($org['skill'] ?? ''));
                        self::setChildXml($newDom, 'ssk', (string)($org['exskill'] ?? ''));
                        // tal_add/soul_add are also child nodes used by detail panel calculations.
                        self::setChildXml($newDom, 'tal_add', (string)($org['tal_add_xml'] ?? ''));
                        self::setChildXml($newDom, 'soul_add', (string)($org['soul_add_xml'] ?? ''));
                        self::setChildXml($newDom, 'tals', (string)($org['tals_xml'] ?? ''));
                        self::setChildXml($newDom, 'soul', '<soul>' . (int)($org['soul_value'] ?? 0) . '</soul>');
                        $parentDom->appendChild($newDom);
                    }
                } else {
                    $item = $sx->warehouse->organisms->addChild('item');
                    $item->addAttribute('id', (string)$orgId);
                    $item->addAttribute('pid', (string)$tplId);
                    $item->addAttribute('at', (string)((int)($org['attack'] ?? (100 + $level * 10))));
                    $item->addAttribute('mi', (string)((int)($org['miss'] ?? 100)));
                    $item->addAttribute('sp', (string)((int)($org['speed'] ?? 100)));
                    $item->addAttribute('pr', (string)((int)($org['precision_val'] ?? $org['precision'] ?? 100)));
                    $item->addAttribute('new_miss', (string)((int)($org['new_miss'] ?? 100)));
                    $item->addAttribute('new_precision', (string)((int)($org['new_precision'] ?? 100)));
                    $item->addAttribute('gr', (string)$level);
                    $item->addAttribute('ex', (string)$exp);
                    $item->addAttribute('ema', (string)(max(1, $level) * 1000));
                    $item->addAttribute('emi', (string)(max(0, $level - 1) * 1000));
                    $item->addAttribute('hp', (string)$hp);
                    $item->addAttribute('hm', (string)((int)($org['hp_max'] ?? $hp)));
                    $item->addAttribute('im', (string)$quality);
                    $item->addAttribute('qu', self::qualityNameByLevel((int)$quality));
                    $item->addAttribute('dq', (string)((int)($org['dq'] ?? 0)));
                    $item->addAttribute('gi', (string)((int)($org['gi'] ?? 0)));
                    $item->addAttribute('ma', (string)((int)($org['mature'] ?? 1)));
                    $item->addAttribute('ss', (string)((int)($org['ss'] ?? 0)));
                    $item->addAttribute('sh', (string)((int)($org['sh'] ?? 0)));
                    $item->addAttribute('sa', (string)((int)($org['sa'] ?? 0)));
                    $item->addAttribute('spr', (string)((int)($org['spr'] ?? 0)));
                    $item->addAttribute('sm', (string)((int)($org['sm'] ?? 0)));
                    $item->addAttribute('new_syn_precision', (string)((int)($org['new_syn_precision'] ?? 0)));
                    $item->addAttribute('new_syn_miss', (string)((int)($org['new_syn_miss'] ?? 0)));
                    $item->addAttribute('fight', (string)((int)($org['fight'] ?? 0)));
                }
            }
        }

        $out = $sx->asXML();
        return is_string($out) && $out !== '' ? $out : $templateXml;
    }
}
