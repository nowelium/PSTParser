<?php

require 'PSTParser.php';

$configure = new ParserConfigure;
$configure->ignoreCase = true;
$configure->classPrefix = 'PST';

$parser = new PSTParser($configure);
$parser->TOKEN->OPEN = array('(', '{');
$parser->TOKEN->CLOSE = array(')', '}');
$parser->TOKEN->NUMBER = '/^[0-9]+$/';
$parser->TOKEN->LETTER = '/^[a-zA-Z]+$/';
$parser->TOKEN->OPERATOR = array('+', '-', '*', '/');
$parser->SKIP->WHITESPACE = array(' ', '/^(\s.+)$/', '\t', '');

$args = $parser->Grammar->create('Args');
$args->start($parser->TOKEN->OPEN);
$args->moreExp('Symbol');
$args->close($parser->TOKEN->CLOSE);

$symbol = $parser->Grammar->create('Symbol');
$symbol->start();
$symbol->more($parser->TOKEN->LETTER);
$symbol->more($parser->TOKEN->OPERATOR);
$symbol->more($parser->TOKEN->NUMBER);
$symbol->close();

$message = $parser->Grammar->create('Message');
$message->start();
$message->moreExp('Args');
$message->moreExp('Symbol');
$message->close();

$parser->Grammar->grammarName('Message');

$statement = <<< SCRIPT
    (+ 1 3)
SCRIPT;
$node = $parser->parse($statement);

class MainVisitor extends AbstractPSTVisitor {
    public function visit(INode $node, stdClass $obj){
        $node->acceptChildren($this, $obj);
    }
    public function visitOpen(PSTOpenNode $node, stdClass $obj){
        $node->acceptChildren($this, $obj);
    }
    public function visitClose(PSTCloseNode $node, stdClass $obj){
        $node->acceptChildren($this, $obj);
    }
    public function visitNumber(PSTNumberNode $node, stdClass $obj){
        return (double) $node->getImage();
    }
    public function visitOperator(PSTOperatorNode $node, stdClass $obj){
        return (string) $node->getImage();
    }
    public function visitArguments(PSTArgumentsNode $node, stdClass $obj){
        $children = $node->getChildren();
        var_dump($children);
        $c = count($children);
        for($i = 0; $i < $c; $i += 2){
        }
    }
    public function visitSymbol(PSTSymbolNode $node, stdClass $obj){
        $children = $node->getChildren();
        foreach($children as $child){
            $child->accept($this, $obj);
        }
    }
    public function visitMessage(PSTMessageNode $node, stdClass $obj){
        $node->acceptChildren($this, $obj);
    }
}
$context = new stdClass;
$visitor = new MainVisitor;
$node->accept($visitor, $context);

echo '[result]: ', $context->result, PHP_EOL;

?>
