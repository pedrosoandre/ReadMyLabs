ReadMyLabs — RAG de exames: de onde baixar os arquivos e como importar
========================================================================
Coloque os arquivos baixados NESTA pasta (rag/ingest/fontes/).
Os scripts de ingestão são OFFLINE (rodam na sua máquina, não no servidor).
Fluxo: baixar -> colocar aqui -> rodar o script -> no fim, rodar os embeddings.

Pré-requisitos:
  - VOYAGE_API_KEY no .env  (https://dash.voyageai.com) — só para o passo de embeddings
  - PHP na máquina onde for rodar os scripts


OPÇÃO RÁPIDA (sem download e sem Voyage) — comece por aqui
----------------------------------------------------------
   Já vem embutida no projeto uma lista curada de ~90 exames comuns no Brasil.
   Melhora o reconhecimento de nomes na hora, sem baixar nada:

       php rag/ingest/01_seed_curado.php

   O LOINC (abaixo) é a versão "completa" para depois.


1) LOINC — versão completa (maior impacto; opcional)
------------------------------------------------
   O que resolve: "o que é este exame" + sinônimos (TGO = AST = aspartato aminotransferase).
   Licença: gratuita (LOINC License) — feita para ser redistribuída.

   a) Crie conta grátis: https://loinc.org/downloads/  -> aceite a licença
   b) Baixe "LOINC Table File (CSV)"  -> o .zip contém Loinc.csv
   c) Baixe a tradução Português (Brasil): https://loinc.org/international/portuguese/
      -> arquivo tipo  ptBR<versao>LinguisticVariant.csv
   d) Coloque os dois aqui (rag/ingest/fontes/) e rode:

      php rag/ingest/01_loinc_import.php ingest/fontes/Loinc.csv ingest/fontes/ptBR.csv

   (Por padrão importa só classes laboratoriais comuns — base curada e rápida.
    Os nomes exatos dos arquivos mudam por versão; passe o caminho como argumento.)


2) MANUAL DE EXAMES PÚBLICO — enriquece as respostas (kb_chunks)
----------------------------------------------------------------
   Fonte pública (governo): Manual de Exames da rede SUS-BH (PMBH).
   URL: https://prefeitura.pbh.gov.br/sites/default/files/estrutura-de-governo/saude/2018/documentos/Laboratorios/manual_exames_laboratoriais_rede_SUS-BH.pdf

   a) Baixe o PDF
   b) Converta para texto:   pdftotext manual.pdf sus_bh.txt   (ou copie/cole num .txt)
   c) Salve como rag/ingest/fontes/sus_bh.txt e rode:

      php rag/ingest/02_manuais_import.php ingest/fontes/sus_bh.txt "Manual SUS-BH" "público" "https://prefeitura.pbh.gov.br/..."


3) VALORES DE REFERÊNCIA DA POPULAÇÃO BRASILEIRA (opcional, refina faixas)
--------------------------------------------------------------------------
   - SciELO/PNS (artigo aberto): colesterol, HbA1c, creatinina, hemograma (BR)
     https://www.scielo.br/j/rbepid/a/79JFJqJnBqcpgFL4CHVGdxS/?format=pdf&lang=pt
   - Manuais MSD (PT): https://www.msdmanuals.com/pt/profissional/recursos/valores-laboratoriais-normais/valores-laboratoriais-normais
   - MDSaúde: https://www.mdsaude.com/exames-complementares/valor-de-referencia/
   Use as FAIXAS (fatos) para ajustar a tabela, ou importe trechos como chunk (passo 2).


4) DEPOIS DE IMPORTAR TUDO — gerar embeddings e ligar o front
-------------------------------------------------------------
   php rag/ingest/03_embeddings.php          (precisa de VOYAGE_API_KEY no .env)
   Depois, em index.html:  const RAG_HABILITADO = true;   -> botão "Entenda seu exame" aparece


A LINHA QUE NÃO SE CRUZA (jurídico)
-----------------------------------
   OK  - Faixas de referência dos labs (Fleury, Hermes Pardini, DASA): são FATOS, pode usar.
   NÃO - Texto explicativo desses labs: tem direito autoral — não copiar (escrevemos o nosso).
   NÃO - PDFs de exames de pacientes: dado sensível (LGPD) — nunca entra na base.


ORDEM RECOMENDADA: LOINC (1) -> embeddings (4) -> manual público (2). O resto é refino.
