<?php

interface IParser {
    public function parse($string);
}

class ParserConfigure extends stdClass {
    public $classPrefix = 'AST';
    public $ignoreCase = true;
    public $baseNodeClass = 'SimpleNode';
    public $visitorBaseInterface = 'IVisitor';
    public $lookahead = -1;
    const VISITOR_SUFFIX = 'Visitor';
    const NODE_SUFFIX = 'Node';
    const BASE_OBJ = 'stdClass';
}

class PSTParser implements IParser {
    private $TOKEN;
    private $SKIP;
    private $EOF;
    private $Grammar;
    private $configure;
    public function __construct(ParserConfigure $configure){
        $this->TOKEN = new Patterns($configure);
        $this->SKIP = new Patterns($configure);
        $this->Grammar = ParserGrammar::getInstance($this);
        $this->TOKEN->EOF = new TokenPattern('EOF', PHP_EOL);
        $this->configure = $configure;
    }
    public function parse($text){
        $TOKEN = $this->TOKEN;
        $SKIP = $this->SKIP;
        $Grammar = $this->Grammar;

        $tokenNames = array_merge($TOKEN->getTokenNames(), $Grammar->getExpressionNames());
        $this->generateVisitor($tokenNames);
        $this->generateNodes($tokenNames);

        $tokenizer = new PSTTokenizer($text);
        $tokenizer->setTokenPattern(new PatternCall($TOKEN->getTokenPatterns()));
        $tokenizer->setSkipTokenPattern(new PatternCall($SKIP->getTokenPatterns()));
        $tokenizer->setLookahead($this->configure->lookahead);
        $tokenizer->setClassPrefix($this->configure->classPrefix);
        $tokenizer->rewind();

        $grammar = $Grammar->getGrammar();
        $context = new GrammarContext($this->configure);
        $context->setRootNode($context->createNode($grammar->getName()));
        $grammarParser = new GrammarInterpreter($grammar);
        $grammarParser->parse($tokenizer, $context);
        return $context->getRootNode();
    }
    public function __get($name){
        return $this->$name;
    }
    protected function generateVisitor(array $nodeNames){
        $nodePrefix = $this->configure->classPrefix;
        $visitorPrefix = $this->configure->classPrefix;
        $visitorBase = $this->configure->visitorBaseInterface;
        $genNames = array();
        $code = array();
        foreach($nodeNames as $name){
            $name = $name[0] . strtolower(substr($name, 1));
            if(in_array($name, $genNames)){
                continue;
            }
            $genNames[] = $name;
            $nodeName = $nodePrefix . $name . ParserConfigure::NODE_SUFFIX;
            $code[] = "public function visit${name}(${nodeName} \$node, stdClass \$obj)";
        }
        $visitorName = $visitorPrefix . ParserConfigure::VISITOR_SUFFIX;
        $interfacePre = "interface ${visitorName} extends ${visitorBase} {" . PHP_EOL;
        $interface = $interfacePre . join(';'. PHP_EOL, $code) . ';' . PHP_EOL;
        $interface .= '}' . PHP_EOL;

        $absClassPre = "abstract class Abstract${visitorName} implements ${visitorName} {" . PHP_EOL;
        $absJoinCode = '{echo $node, PHP_EOL;$node->acceptChildren($this, $obj);}';
        $absClass = $absClassPre . join($absJoinCode . PHP_EOL, $code) . $absJoinCode. PHP_EOL;
        $absClass .= '}' . PHP_EOL;

        echo '[EVAL] Visitor', PHP_EOL, $interface, PHP_EOL;
        eval($interface);

        echo '[EVAL] AbstractVisitor', PHP_EOL, $absClass, PHP_EOL;
        eval($absClass);
    }
    protected function generateNodes(array $nodeNames){
        $nodePrefix = $this->configure->classPrefix;
        $visitorPrefix = $this->configure->classPrefix;
        $baseNodeClass = $this->configure->baseNodeClass;
        $visitorName = $visitorPrefix . ParserConfigure::VISITOR_SUFFIX;

        $genNames = array();
        $code = '';
        foreach($nodeNames as $name){
            $name = $name[0] . strtolower(substr($name, 1));
            if(in_array($name, $genNames)){
                continue;
            }
            $genNames[] = $name;
            $nodeName = $nodePrefix . $name . ParserConfigure::NODE_SUFFIX;
            $code .=<<< EOD
class ${nodeName} extends $baseNodeClass {
    const NODE = '${name}';
    public function accept(IVisitor \$visitor, stdClass \$obj){
        return \$visitor->visit${name}(\$this, \$obj);
    }
}
EOD;
            $code .= PHP_EOL;
        }
        echo '[EVAL] Nodes', PHP_EOL, $code, PHP_EOL;
        eval($code);
    }
}

class GrammarContext {
    private $configure;
    private $rootNode;
    private $nodes = array();
    private $marks = array();
    private $index = 0;
    private $current = 0;
    public function __construct(ParserConfigure $configure){
        $this->configure = $configure;
    }
    public function getRootNode(){
        $this->rootNode->addChild($this->nodes[0]);
        return $this->rootNode;
    }
    public function setRootNode(INode $node){
        $this->rootNode = $node;
    }
    public function openNodeScope(INode $node){
        $this->marks[] = $this->current;
        $this->current = $this->index;
        $node->open();
    }
    public function addNode(INode $node){
        $this->nodes[] = $node;
        ++$this->index;
    }
    public function closeNodeScope(INode $node){
        $arity = $this->arity();
        $this->current = array_pop($this->marks);
        while($arity-- > 0){
            $n = $this->pop();
            $n->setParent($node);
            $node->addChild($n);
        }
        $node->close();
        $this->addNode($node);
    }
    protected function pop(){
        if(--$this->index < $this->current){
            $this->current = array_pop($this->marks);
        }
        return array_pop($this->nodes);
    }
    protected function arity(){
        return $this->index - $this->current;
    }
    public function createNode($nodeName){
        // TODO: replace tp $NodeName::create($name, null)
        $className = $this->configure->classPrefix . $nodeName . ParserConfigure::NODE_SUFFIX;
        return new $className($nodeName, null);
    }
}

interface IGrammarInterpreter {
    public function parse(ITokenizer $tokenizer, GrammarContext $context);
}

class GrammarInterpreter implements IGrammarInterpreter {
    private $grammar;
    public function __construct(ParserGrammar $grammar){
        $this->grammar = $grammar;
    }
    public function parse(ITokenizer $tokenizer, GrammarContext $context){
        if(!$tokenizer->valid()){
            throw new RuntimeException('Tokenizer::valid was fail');
        }
        $start = new StartGrammarInterpreter($this->grammar);
        $start->parse($tokenizer, $context);
        $more = new ListMoreGrammarInterpreter($this->grammar);
        $more->parse($tokenizer, $context);
        $close = new CloseGrammarInterpreter($this->grammar);
        $close->parse($tokenizer, $context);
    }
}

class StartGrammarInterpreter implements IGrammarInterpreter {
    private $grammar;
    private $compare;
    public function __construct(ParserGrammar $grammar){
        $this->grammar = $grammar;
        $this->compare = $grammar->getStart();
    }
    public function parse(ITokenizer $tokenizer, GrammarContext $context){
        $current = $tokenizer->current();
        if($this->match($current)){
            $context->openNodeScope($context->createNode($this->grammar->getName()));
            $context->addNode($current);
            $tokenizer->next();
        }
        $more = new ListMoreGrammarInterpreter($this->grammar);
        $more->parse($tokenizer, $context);
        $close = new CloseGrammarInterpreter($this->grammar);
        $close->parse($tokenizer, $context);
    }
    public function match(INode $token){
        return strcmp($this->compare->getName(), $token->getName()) === 0;
    }
}

class ListMoreGrammarInterpreter implements IGrammarInterpreter {
    private $grammar;
    private $mores = array();
    public function __construct(ParserGrammar $grammar){
        $this->grammar = $grammar;
        $mores = $grammar->getMore();
        foreach($mores as $more){
            if($more instanceof ParserGrammar){
                $this->mores[] = new StartGrammarInterpreter($more);
            } else {
                $this->mores[] = new MoreGrammarInterpreter($grammar, $more);
            }
        }
    }
    public function parse(ITokenizer $tokenizer, GrammarContext $context){
        foreach($this->mores as $more){
            $more->parse($tokenizer, $context);
        }
        $close = new CloseGrammarInterpreter($this->grammar);
        $close->parse($tokenizer, $context);
    }
}
class MoreGrammarInterpreter implements IGrammarInterpreter {
    private $grammer;
    private $compare;
    public function __construct(ParserGrammar $grammer, Pattern $pattern){
        $this->grammar = $grammer;
        $this->compare = $pattern;
    }
    public function parse(ITokenizer $tokenizer, GrammarContext $context){
        $current = $tokenizer->current();
        if($this->match($current)){
            $context->addNode($current);
            $tokenizer->next();
        }
    }
    public function match(INode $node){
        return strcmp($this->compare->getName(), $node->getName()) === 0;
    }
}

class CloseGrammarInterpreter implements IGrammarInterpreter {
    private $grammar;
    private $compare;
    public function __construct(ParserGrammar $grammar){
        $this->grammar = $grammar;
        $this->compare = $grammar->getClose();
    }
    public function parse(ITokenizer $tokenizer, GrammarContext $context){
        $current = $tokenizer->current();
        if($this->match($current)){
            $context->addNode($current);
            $context->closeNodeScope($context->createNode($this->grammar->getName()));
            $tokenizer->next();
        }
    }
    public function match(INode $token){
        return strcmp($this->compare->getName(), $token->getName()) === 0;
    }
}

class ParserGrammar {
    private static $instances = array();
    private $name;
    private $expressions = array();
    private $start;
    private $close;
    private $more = array();
    private $grammarName;
    private $lock;
    private $lazyExp = array();

    private function __construct($name, $lock){
        $this->name = $name;
        $this->lock = $lock;
    }
    public function getName(){
        return $this->name;
    }
    public static function getInstance($lock){
        $hashCode = spl_object_hash($lock);
        if(self::$instances[$hashCode] === null){
            self::$instances[$hashCode] = new self('root', $lock);
        }
        return self::$instances[$hashCode];
    }
    private function _getInstance(){
        return self::$instances[spl_object_hash($this->lock)];
    }
    public function grammarName($name){
        $this->grammarName = $name;
        $this->compile();
    }
    public function getGrammar(){
        return $this->getExpression($this->grammarName);
    }
    public function getExpressions(){
        return $this->_getInstance()->expressions;
    }
    public function getExpression($name){
        return $this->_getInstance()->expressions[$name];
    }
    public function getExpressionNames(){
        return array_keys($this->_getInstance()->expressions);
    }
    public function setExpression($name, ParserGrammar $my){
        return $this->_getInstance()->expressions[$name] = $my;
    }
    public function hasExpression($expressionName){
        return isset($this->_getInstance()->expressions[$expressionName]);
    }
    public function getStart(){
        return $this->start;
    }
    public function getClose(){
        return $this->close;
    }
    public function getMore(){
        return $this->more;
    }
    public function create($name){
        return $this->setExpression($name, new self($name, $this->lock));
    }
    protected function compile(){
        foreach($this->lazyExp as $lazyExp){
            $instance = $lazyExp->instance;
            $target = $lazyExp->target;
            $expressionName = $lazyExp->expressionName;
            $appendable = $lazyExp->appendable;

            if(!$this->hasExpression($expressionName)){
                throw new RuntimeException('no such expression name "' . $expressionName . '"');
            }

            $exp = $this->getExpression($expressionName);
            if(is_bool($appendable) && $appendable === true){
                array_push($instance->$target, $exp);
            } else {
                $instance->$target = $exp;
            }
        }
    }
    public function start(Pattern $pattern = null){
        if($pattern === null){
            $this->start = new NullPattern;
        } else {
            $this->start = $pattern;
        }
        return $this;
    }
    public function startExp($expressionName){
        $this->addLazyExp('start', $expressionName);
        return $this;
    }
    public function more(Pattern $pattern){
        $this->more[] = $pattern;
        return $this;
    }
    public function moreExp($expressionName){
        $this->addLazyExp('more', $expressionName, true);
        return $this;
    }
    public function close(Pattern $pattern = null){
        if($pattern === null){
            $this->close = new NullPattern;
        } else {
            $this->close = $pattern;
        }
    }
    public function closeExp($expressionName){
        $this->addLazyExp('close', $expressionName);
    }
    protected function addLazyExp($targrt, $expressionName, $appendable = false){
        $lazyExp = new stdClass;
        $lazyExp->instance = $this;
        $lazyExp->target = $targrt;
        $lazyExp->expressionName = $expressionName;
        $lazyExp->appendable = $appendable;
        $this->_getInstance()->lazyExp[] = $lazyExp;
    }
}

class Patterns {
    private $configure;
    private $patterns = array();
    public function __construct(ParserConfigure $configure){
        $this->configure = $configure;
    }
    public function __set($key, $value){
        if($value === null){
            throw new RuntimeException('Null');
        }
        $configure = $this->configure;
        if(is_array($value)){
            $patterns = array();
            foreach($value as $index => $v){
                $patterns[] = self::createPattern($configure, $key, $v);
            }
            $this->patterns[$key] = new MixedTokenPattern($key, $patterns);
        } else {
            $this->patterns[$key] = self::createPattern($configure, $key, $value);
        }
    }
    public function __get($name){
        return $this->patterns[$name];
    }
    public function getTokenPatterns(){
        return $this->patterns;
    }
    public function getTokenNames(){
        return array_keys($this->patterns);
    }
    protected static function createPattern(ParserConfigure $configure, $name, $value){
        if($value instanceof Pattern){
            return $value;
        }
        $size = strlen($value);
        $lastChar = $value[$size - 1];
        if(strcmp($value[0], '/') === 0 && strcmp($lastChar, '/') === 0 && 1 < $size){
            return new RegexPattern($name, $value);
        }
        if($configure->ignoreCase){
            return new CaseInsentiveTokenPattern($name, $value);
        }
        if($size < 1){
            $value = '';
        }
        return new TokenPattern($name, $value);
    }
}

interface Pattern {
    public function match($value);
    public function getName();
    public function duplicate();
    public function __toString();
}

class RegexPattern implements Pattern {
    private $name;
    private $pattern;
    public function __construct($name, $pattern){
        $this->name = $name;
        $this->pattern = $pattern;
    }
    public function getName(){
        return $this->name;
    }
    public function match($value){
        return 0 < preg_match($this->pattern, $value);
    }
    public function duplicate(){
        return true;
    }
    public function __toString(){
        return $this->pattern;
    }
}

class TokenPattern implements Pattern {
    protected $name;
    protected $pattern;
    public function __construct($name, $pattern){
        $this->name = $name;
        $this->pattern = $pattern;
    }
    public function getName(){
        return $this->name;
    }
    public function match($value){
        return strcmp($this->pattern, $value) === 0;
    }
    public function duplicate(){
        return false;
    }
    public function __toString(){
        return $this->pattern;
    }
}

class CaseInsentiveTokenPattern extends TokenPattern {
    public function match($value){
        return strcasecmp($this->pattern, $value) === 0;
    }
}

class MixedTokenPattern implements Pattern {
    private $name;
    private $patterns = array();
    public function __construct($name, array $patterns){
        $this->name = $name;
        $this->patterns = $patterns;
    }
    public function getName(){
        return $this->name;
    }
    public function match($value){
        foreach($this->patterns as $pt){
            if($pt->match($value)){
                return true;
            }
        }
        return false;
    }
    public function duplicate(){
        return false;
    }
    public function __toString(){
        return '[mixed: ' . join(',', $this->patterns) . ']';
    }
}
class NullPattern implements Pattern {
    public function getName(){
        return __CLASS__;
    }
    public function match($value){
        return false;
    }
    public function duplicate(){
        return false;
    }
    public function __toString(){
        return __CLASS__ . '@' . spl_object_hash($this);
    }
}

interface IteratorCall {
    public function iterate($parameter);
    public function iterateFor($name = '__call', $parameter = array());
    public function iterateForFirst($name = '__call', $parameter = array());
}

class UserCallIterate implements IteratorCall {
    protected $iterator;
    private $returnTrueFunc;
    private $continue = true;
    public function __construct(Iterator $iterator){
        $this->iterator = $iterator;
        $this->returnTrueFunc = create_function('', 'return true;');
    }
    public function iterate($params){
        return $this->iterateFor('__call', (array) $params);
    }
    public function iterateFor($name = '__call', $params = array()){
        $this->continue = true;
        return $this->map($name, $params);
    }
    public function iterateForFirst($name = '__call', $params = array()){
        if($this->_returnResultTrue === null){
            $args = '$key, $it, $result';
            $func = 'return $result === true;';
            $this->_returnResultTrue = create_function($args, $func);
        }
        $returnValue = $this->map($name, $params, $this->_returnResultTrue);
        if(is_object($returnValue)){
            return $returnValue;
        }
        return null;
    }
    protected function map($name, $params, $callback = null){
        $results = array();

        if($callback === null){
            $callback = $this->returnTrueFunc;
        }

        $this->iterator->rewind();
        foreach($this->iterator as $key => $it){
            $result = self::call($it, $name, $params);
            $returnValue = new stdClass;
            $returnValue->KEY = $key;
            $returnValue->RESULT = $result;
            $returnValue->DUPLICATE = $it->duplicate();
            if($callback($key, $it, $result)){
                return $returnValue;
            }
            $results[] = $returnValue;
        }
        return $results;
    }
    protected static function call($target, $methodName, $params = array()){
        return call_user_func_array(array($target, $methodName), $params);
    }
}

class PatternCall extends UserCallIterate {
    public function __construct(array $patterns){
        parent::__construct(new ArrayIterator($patterns));
    }
    public final function iterate($param){
        $this->iterator->rewind();
        foreach($this->iterator as $it){
            if(!($it instanceof Pattern)){
                throw new RuntimeException(get_class($it) . ' was not implements Pattern');
            }
            if($it->match($param)){
                return true;
            }
        }
        return false;
    }
}

// fetch can't override for FilterIterator
abstract class AcceptIterator implements OuterIterator {
    protected $iterator;
    protected function __construct(Iterator $iterator){
        $this->iterator = $iterator;
    }
    public function rewind(){
        $this->iterator->rewind();
        $this->fetch();
    }
    public function next(){
        $this->iterator->next();
        $this->fetch();
    }
    public function valid(){
        return $this->iterator->valid();
    }
    public function key(){
        return $this->iterator->key();
    }
    public function current(){
        return $this->iterator->current();
    }
    public function getInnerIterator(){
        return $this->iterator;
    }

    abstract protected function accept();

    protected function fetch(){
        while($this->iterator->valid()){
            if($this->accept()){
                return;
            }
            $this->iterator->next();
        }
    }
}

interface ITokenizer extends Iterator {
    public function setSkipTokenPattern(PatternCall $pattern);
    public function setTokenPattern(PatternCall $pattern);
    public function getImage();
    public function getBeforeToken();
}

class PSTTokenizer extends AcceptIterator implements ITokenizer {
    const SKIP = 0;
    const MORE = 1;
    const NEXT = 3;
    const FIND_TOKEN = 7;
    const LOOP_BREAK = -1;

    private $text;
    private $skipPattern = array();
    private $tokenPattern = array();
    private $value = '';
    private $skipValue = '';

    private $token;
    private $tokenName = '';
    private $beforeToken;
    private $beforeTokenName = '';

    private $hasMoreNext = false;
    private $lookahead = -1;
    private $classPrefix;

    public function __construct($text){
        $this->text = $text;
        parent::__construct(new ArrayIterator(str_split($text)));
    }
    public function setSkipTokenPattern(PatternCall $pattern){
        $this->skipPattern = $pattern;
    }
    public function setTokenPattern(PatternCall $pattern){
        $this->tokenPattern = $pattern;
    }
    public function setLookahead($lookahead){
        $this->lookahead = $lookahead;
    }
    public function setClassPrefix($classPrefix){
        $this->classPrefix = $classPrefix;
    }
    public function rewind(){
        $this->value = '';
        $this->skipValue = '';
        $this->token = null;
        $this->tokenName = '';
        $this->beforeToken = null;
        $this->beforeTokenName = '';
        return parent::rewind();
    }
    public function key(){
        return $this->tokenName;
    }
    public function current(){
        return $this->token;
    }
    public function getImage(){
        return $this->value;
    }
    public function getBeforeToken(){
        return $this->beforeToken;
    }
    private function createNode($nodeKey, $value){
        $key = $nodeKey[0] . strtolower(substr($nodeKey, 1));
        $className = $this->classPrefix . $key . ParserConfigure::NODE_SUFFIX;
        return new $className($nodeKey, $value);
    }
    protected function accept(){
        $current = parent::current();
        if($this->skipPattern->iterate($current)){
            $this->skipValue .= $current;
            if($this->beforeToken !== null){
                $this->value = '';
                return self::FIND_TOKEN;
            }
            return self::SKIP;
        }
        $this->value .= $current;
        if($this->skipPattern->iterate($this->value)){
            return self::SKIP;
        }
        $iter = parent::getInnerIterator();
        $index = $iter->key() + 1;
        $counter = 0;
        $value = $this->value;
        for($i = $index; $iter->offsetExists($i); ++$i, ++$counter){
            $value .= $iter->offsetGet($i);
            $r = $this->tokenPattern->iterateForFirst('match', $value);
            if($r === null){
                break;
            }
            if($iter->offsetExists($i + 1)){
                $c = $iter->offsetGet($i + 1);
                $next = $this->tokenPattern->iterateForFirst('match', $c);
                if(strcmp($r->KEY, $next->KEY) !== 0){
                    $this->beforeToken = $this->createNode($r->KEY, $value);
                    $this->value = '';
                    $iter->seek($index);
                    return self::FIND_TOKEN;
                }
                $index++;
                continue;
            }

            if(strcmp($r->KEY, $result->KEY) !== 0){
                $this->beforeToken = $this->createNode($r->KEY, $value);
                $this->value = '';
                $iter->seek($index);
                return self::FIND_TOKEN;
            }
            $index++;
        }
        $iter->seek($index - 1);
        $result = $this->tokenPattern->iterateForFirst('match', $this->value);
        if($result === null){
            return self::SKIP;
        }
        $this->beforeToken = $this->createNode($result->KEY, $this->value);
        $this->value = '';
        return self::FIND_TOKEN;
    }
    protected function fetch(){
        $iterator = parent::getInnerIterator();
        while($this->valid()){
            $this->hasMoreNext = $iterator->offsetExists(parent::key() + 1);
            $code = (int) $this->accept();
            switch($code){
            case self::MORE:
                break;
            case self::SKIP:
                $this->next();
                continue;
            case self::NEXT:
                $this->next();
                continue;
            case self::FIND_TOKEN:
                $this->token = $this->beforeToken;
                $this->tokenName = $this->beforeToken->getName();
                $this->beforeToken = null;
                return;
            case self::LOOP_BREAK:
                return;
            default:
                throw new RuntimeException('invalid parameter self::accept(): ' . $code);
            }
            return;
        }
    }
}

interface IVisitor {
    public function visit(INode $node, stdClass $obj);
}

interface INode {
    public function getName();
    public function getImage();
    public function open();
    public function close();
    public function getParent();
    public function setParent(INode $node);
    public function getChildren();
    public function getChild($index);
    public function setChild(INode $node, $index);
    public function addChild(INode $node);
    public function getChildrenSize();
    public function accept(IVisitor $visitor, stdClass $obj);
}

class SimpleNode implements INode {
    const DUMP_CHAR = '|--';
    private $parent;
    private $children = array();
    private $name;
    private $image;
    private $dumpstr = '';
    public function __construct($name, $image){
        $this->name = $name;
        $this->image = $image;
    }
    public function getName(){
        return $this->name;
    }
    public function getImage(){
        return $this->image;
    }
    public function open(){
    }
    public function close(){
    }
    public function getParent(){
        return $this->parent;
    }
    public function setParent(INode $node){
        $this->parent = $node;
    }
    public function getChildren(){
        return $this->children;
    }
    public function getChild($index){
        return $this->children[$index];
    }
    public function setChild(INode $node, $index){
        $this->children[$index] = $node;
    }
    public function addChild(INode $node){
        $this->children[] = $node;
    }
    public function getChildrenSize(){
        return count($this->children);
    }
    public function accept(IVisitor $visitor, stdClass $obj){
        return $visitor->visit($this, $obj);
    }
    public function acceptChildren(IVisitor $visitor, stdClass $obj){
        foreach($this->children as $child){
            $child->dumpstr = $this->dumpstr . self::DUMP_CHAR;
            $child->accept($visitor, $obj);
        }
    }
    public function __toString(){
        $image = '';
        if($this->image !== null){
            $image = '"' . $this->image . '"';
        }
        return $this->dumpstr . '[' . get_class($this) . ':' . $this->name . '] ' . $image;
    }
}

?>
