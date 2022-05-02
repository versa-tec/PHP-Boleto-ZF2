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
 */

namespace PhpBoletoZf2\Factory;

use Zend\Stdlib\Hydrator\ClassMethods;
use Zend\Barcode\Barcode;
use PhpBoletoZf2\Factory\AbstractBoletoFactory;
use PhpBoletoZf2\Lib\Util;

class Safra extends
    AbstractBoletoFactory {
    protected $codigoBanco = '422';

    /**
     * @return string
     */
    public function getCodigoBanco()
    {
        return $this->codigoBanco;
    }

    /**
     * @param string $codigoBanco
     * @return Safra
     */
    public function setCodigoBanco($codigoBanco)
    {
        $this->codigoBanco = $codigoBanco;

        return $this;
    }

    public function prepare()
    {

        /**
         * adicionando dados das instruções e demonstrativo no boleto
         */
        (new ClassMethods())->hydrate($this->config['php-zf2-boleto']['instrucoes'], $this->getBoleto());

        /**
         * Compondo o Nosso Número e seu dígito verificador
         */

        $nossonumero = str_pad($this->getCedente()->getCarteira(), 8, 0, STR_PAD_RIGHT);

        $nossoNumeroProcessado = \str_pad($this->getBoleto()->getNossoNumero(), 9, '0', STR_PAD_LEFT);
        $nossoNumeroDV = Util::digitoVerificadorNossoNumero($this->getBoleto()->getNossoNumero());
        $nossoNumeroDV = $nossoNumeroDV == "P" ? "0" : $nossoNumeroDV;

        /**
         * Calcula o fator do vencimento (número inteiro que representa a data de vencimento na linha digitavel)
         */
        $fatorVencimento = (Util::fatorVencimento($this->getBoleto()->getDataVencimento()->format("d/m/Y"))) * 1;

        /**
         * Processando o valor para aplicação na linha digitável e no código de barras
         */
        $valor = preg_replace(
            "/[^0-9]/",
            "",
            $this->getBoleto()->getValor()
        ); // removendo formatação do número
        $valorProcessado = \str_pad($valor, 10, '0', STR_PAD_LEFT);

        /**
         * Calcula o dígito verificador do código de barras
         */
        $contaCedenteDv = Util::digitoVerificadorNossoNumero($this->getCedente()->getContaCedente());
        $contaCedenteDv = $contaCedenteDv == "P" ? "0" : $contaCedenteDv;
        $this->getCedente()->setContaCedenteDv($contaCedenteDv);

        $strNossoNumeroProcessado = str_pad($nossoNumeroProcessado, 15, '0', STR_PAD_LEFT);
        preg_match('/(\d{3})(\d{3})(\d{9})/', $strNossoNumeroProcessado, $arrNossoNumeroProcessado);
        $strCarteira = $this->getCedente()->getCarteira();

        /**
         * Formatando o Nosso Número para impressão
         */

        $nossoNumeroFormatado = $nossonumero . $nossoNumeroProcessado;
        $digitoNossoNumero = Util::digitoVerificadorNossoNumero(
            $nossoNumeroFormatado
        ) == 'P' ? 0 : Util::digitoVerificadorNossoNumero($nossoNumeroFormatado);
        $nossoNumeroFormatado = $nossoNumeroFormatado . '-' . $digitoNossoNumero;

        $agencia = str_pad((substr($this->getCedente()->getAgencia(), 0, 4)), 4, '0', STR_PAD_LEFT);

        $agenciaCodigo = (
            substr($this->getCedente()->getAgencia(),0,5) . ' / ' .
            $this->getCedente()->getContaCedente() . '-' . $contaCedenteDv
        );

        //Bloco1
        $strParte1 = $this->getBanco()->getCodigoBanco() . $this->getBanco()->getMoeda() . '7' . $agencia;

        $dv_01 = Util::calculoDVSafra($strParte1);
        $strParte1 .= $dv_01;

        //Bloco2
        $strParte2 =
            (substr($this->getCedente()->getAgencia(), -1)) .
            str_pad($this->getCedente()->getContaCorrente(), 9, '0', STR_PAD_LEFT);

        $dv_02 = Util::calculoDVSafra($strParte2);
        $strParte2 .= $dv_02;

        //Bloco3
        $strParte3 = str_pad($this->getBoleto()->getNossoNumero(), 9, '0', STR_PAD_LEFT) . '2';//tipo cobrança
        $dv_03 = Util::calculoDVSafra($strParte3);
        $strParte3 .= $dv_03;

        $strCalcDac = (
            $this->getBanco()->getCodigoBanco() .
            $this->getBanco()->getMoeda() .
            $fatorVencimento .
            $valorProcessado .
            '7' .
            (str_pad((substr($this->getCedente()->getAgencia(), 0, 5)), 5, '0', STR_PAD_LEFT)) .
            str_pad($this->getCedente()->getContaCorrente(), 8, '0', STR_PAD_LEFT) .
            str_pad($this->getBoleto()->getNossoNumero(), 9, '0', STR_PAD_LEFT) . '2'
        );

        $dac = Util::calculoDac($strCalcDac);
        //$dacTeste = Util::calculoDac('4229807300000010007025000020264030000010542'); Teste

        $strLinha = (
            $strParte1 .
            $strParte2 .
            $strParte3 .
            $dac .
            $fatorVencimento .
            $valorProcessado
        );

        $strCodigoBarras = (
            substr($strCalcDac,0,4).$dac.substr($strCalcDac,4)
        );


        $this->getCedente()->setAgenciaCodigo($agenciaCodigo);

        /**
         * Iniciando opções para criação do Código de Barras
         */
        $barcodeOptions = array('text' => $strCodigoBarras);

        /**
         * Criando o código de barras em uma imagem e retornando seu base64
         */
        $codigoDeBarras = Barcode::factory(
            'Code25interleaved',
            'PhpBoletoZf2\Lib\Barcode\Renderer\Base64',
            $barcodeOptions,
            array()
        );

        $linhaParte1 = substr($strLinha, 0, 5) . '.';
        $linhaParte2 = substr($strLinha, 5, 5) . ' ';
        $linhaParte3 = substr($strLinha, 10, 5) . '.';
        $linhaParte4 = substr($strLinha, 15, 6) . ' ';
        $linhaParte5 = substr($strLinha, 21, 5) . '.';
        $linhaParte6 = substr($strLinha, 26, 6) . ' ';
        $linhaParte7 = substr($strLinha, 32, 1) . ' ';
        $linhaParte8 = substr($strLinha, 33);

        $strLinhaDigitavel = $linhaParte1 . $linhaParte2 . $linhaParte3 . $linhaParte4 . $linhaParte5 . $linhaParte6 . $linhaParte7 . $linhaParte8;

        /**
         * Termina de hidratar o objetodo boleto
         */
        $this->getBoleto()
            ->setCodigoDeBarras($codigoDeBarras)
            ->setLinhaDigitavel($strLinhaDigitavel)
            ->setNossoNumeroFormatado($nossoNumeroFormatado);

        return $this;
    }

}
