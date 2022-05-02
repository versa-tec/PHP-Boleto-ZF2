<?php

/**
 * PHP Boleto ZF2 - Versão Beta
 *
 * Este arquivo está disponível sob a Licença GPL disponível pela Web
 * em http://pt.wikipedia.org/wiki/GNU_General_Public_License
 * Você deve ter recebido uma cópia da GNU Public License junto com
 * este pacote; se não, escreva para:
 *
 * Free Software Foundation, Inc.
 * 59 Temple Place - Suite 330
 * Boston, MA 02111-1307, USA.
 *
 * Originado do Projeto BoletoPhp: http://www.boletophp.com.br
 *
 * Adaptação ao Zend Framework 2: João G. Zanon Jr. <jot@jot.com.br>
 *
 * Matheus Ferreira S. <santana.matheusferreira@gmail.com>
 */

 namespace PhpBoletoZf2\Factory;

 use Zend\Stdlib\Hydrator\ClassMethods;
 use Zend\Barcode\Barcode;
 use PhpBoletoZf2\Factory\AbstractBoletoFactory;
 use PhpBoletoZf2\Lib\Util;

 class Bancoob extends AbstractBoletoFactory
 {
     protected $codigoBanco = '756';

     public function prepare()
     {
         // adicionando dados das instruções e demonstrativo no boleto
         (new ClassMethods())->hydrate($this->config['php-zf2-boleto']['instrucoes'], $this->getBoleto());

         // adicionando valores default de configuração do cedente
         (new ClassMethods())->hydrate(
             $this->config['php-zf2-boleto'][$this->banco->getCodigoBanco()]['dados_cedente'],
             $this->getCedente()
         );

         $nossoNumeroProcessado = (int)$this->getBoleto()->getNossoNumero();
         $nossoNumeroProcessado = str_pad($nossoNumeroProcessado, 7, '0', STR_PAD_LEFT);

         // Calcula o fator do vencimento (número inteiro que representa a data de vencimento na linha digitavel)
         $fatorVencimento = Util::fatorVencimento($this->getBoleto()->getDataVencimento()->format("d/m/Y"));
         $fatorVencimento = str_pad($fatorVencimento, 4, '0', STR_PAD_LEFT);

         // Processando o valor para aplicação na linha digitável e no código de barras
         // removendo formatação do número
         $valor           = preg_replace("/[^0-9]/", "", $this->getBoleto()->getValor());
         $valorProcessado = str_pad($valor, 10, '0', STR_PAD_LEFT);

         $parcela = $this->getBoleto()->getQuantidade();
         $parcela = str_pad(($parcela ? $parcela : 1), 3, '0', STR_PAD_LEFT);

         $numeroCliente = (int)$this->getCedente()->getConvenio();
         $numeroCliente7 = str_pad($numeroCliente, 7, '0', STR_PAD_LEFT);
         $numeroCliente = str_pad($numeroCliente, 10, '0', STR_PAD_LEFT);

         $numeroCooperativa = (int)$this->getCedente()->getAgencia();
         $numeroCooperativa = str_pad($numeroCooperativa, 4, '0', STR_PAD_LEFT);


         /**
          * Calcula digito verificador nosso número boletos Bancoob
          * 3197 regra sicoob
          */
         $sequencia     = ($numeroCooperativa . $numeroCliente . $nossoNumeroProcessado);
         $dvNossoNumero = Util::digitoVerificadorNossoNumeroBancoob($sequencia, '3197');

         $nossoNumeroFormatado = $nossoNumeroProcessado . $dvNossoNumero;

         // modalidade de cobranca
         $carteira = $this->getCedente()->getCarteira();
         $carteira = ($carteira?$carteira: 1);
         $variacao = $this->getCedente()->getVariacaoCarteira();
         $variacao = str_pad(($variacao?$variacao: 2), 2, '0', STR_PAD_LEFT);

         $campoLivre = "$carteira$numeroCooperativa$variacao$numeroCliente7$nossoNumeroFormatado$parcela";

         // Calcula o dígito verificador do código de barras
         $DV = Util::digitoVerificadorBarra(
                $this->getBanco()->getCodigoBanco()
                . $this->getBanco()->getMoeda()
                . $fatorVencimento
                . $valorProcessado
                . $campoLivre
         );

         /**
          * Compondo a linha base para formação da Linha Digitável e do Código de Barras
          */
         $strLinha = $this->getBanco()->getCodigoBanco()
                . $this->getBanco()->getMoeda()
                . $DV
                . $fatorVencimento
                . $valorProcessado
                . $campoLivre
                ;

         // Formatando os dados bancários do cedente para impressão
         $this->getCedente()->setAgenciaCodigo($this->getCedente()->getAgenciaDv()
                . ' / '
                . $this->getCedente()->getCodigocliente()
         );

         // Iniciando opções para criação do Código de Barras
         $barcodeOptions = array('text' => $strLinha);

         // Criando o código de barras em uma imagem e retornando seu base64
         $codigoDeBarras = Barcode::factory('Code25interleaved', 'PhpBoletoZf2\Lib\Barcode\Renderer\Base64', $barcodeOptions, array());

         // Termina de hidratar o objetodo boleto
         $this->getBoleto()
                ->setCodigoDeBarras($codigoDeBarras)
                ->setLinhaDigitavel(Util::montaLinhaDigitavel($strLinha))
                ->setNossoNumeroFormatado($nossoNumeroFormatado);

         return $this;
     }
 }