# Roadmap — Detecção real de achados em imagem (radiologia) no ReadMyLabs

> **Status:** estratégico/regulatório. Nada disto entra em produção antes do gate regulatório (P0).
> O comportamento **não-diagnóstico** atual do modo imagem **permanece** até a trilha regulada existir.

## Contexto / o problema
Hoje o app, ao receber a IMAGEM do exame (filme) sem laudo, descreve a anatomia de forma
NÃO-diagnóstica — por design. O usuário quer **identificar achados a partir da imagem**. Isso é
possível, mas **não** com um LLM genérico nem como app de consumo: é território de **dispositivo
médico regulado**. Este documento descreve o caminho legítimo.

## A restrição que define tudo
1. **Diagnóstico é ato médico (CFM).** Quem emite laudo/diagnóstico é um **médico radiologista**
   registrado (CRM). Teleradiologia tem regras próprias (CFM 2.314/2022). IA = **apoio à decisão**
   (CADx), nunca diagnóstico autônomo que fala direto com o paciente.
2. **Software de imagem diagnóstica = SaMD, regulado pela ANVISA** (RDC 657/2022 + marco de
   dispositivos). Detecção de lesão cerebral cai em **classe de risco alta** (II/III) → exige
   registro, ISO 13485 (gestão da qualidade), ISO 14971 (gestão de risco) e avaliação clínica.
3. **LLM genérico (Claude Vision) NÃO serve** para isso: não é validado, erra nos dois sentidos
   (falso negativo que mata, falso positivo que apavora) e "acerta" quando há pistas na imagem
   (ex.: calipers desenhados). Não é, e não pode ser, o detector.

## Princípio de produto (o reposicionamento)
Nosso diferencial **legal e defensável** não é "ser o radiologista" — é **acesso + última milha**:
- **Detecção/laudo** vêm de um **radiologista** (opcionalmente acelerado por uma IA certificada).
- **O app** já sabe traduzir o laudo para linguagem leiga (`responderImagem` caminho laudo /
  `analisarExplicacao`). Essa é a peça que ninguém faz bem e que já temos.
- Ou seja: a "detecção" que o usuário quer chega via **teleradiologia**, e o app entrega o valor
  no fim da cadeia.

## Como obter detecção de verdade — duas opções
- **(A) Licenciar/integrar um CADx já certificado (RECOMENDADO).** Existem produtos de IA de
  neuroimagem com clearance (FDA/CE e alguns ANVISA) para triagem/realce de achados. Integra-se via
  API; o **registro regulatório é do fornecedor**. Rápido, sem construir modelo.
- **(B) Construir modelo próprio (NÃO recomendado agora).** Exige datasets DICOM anotados por
  radiologistas, expertise de ML, estudos de validação e registro ANVISA próprio. Multi-anos, caro,
  alto risco. Só faz sentido em escala, depois de validar o negócio.

## Arquitetura (segue os princípios do projeto)
Dependência externa **atrás de interface** (como `chamarClaude`/`voyage`):
- Novo subsistema `radio/` (modular, autocontido). Interface `detectarAchados(estudoDicom): array`
  com implementação trocável (fornecedor CADx certificado). Sem CADx configurado → recurso desligado
  (falha para desligado), app roda igual.
- **Human-in-the-loop** obrigatório: fila de estudos → painel do radiologista (IA pré-marca/prioriza)
  → radiologista emite o **laudo** → laudo entra no fluxo de explicação que **já existe**.
- **DICOM, não JPEG.** Entrada diagnóstica é o estudo DICOM (série completa) do serviço de imagem,
  não foto de tela. Pipeline: ingestão DICOM → de-identificação → storage seguro → CADx → painel.
- **LGPD/segurança reforçada:** dado de imagem é sensível; criptografia em repouso, trilha de
  auditoria, consentimento explícito, retenção definida. (Diferente do app atual, que não persiste.)

## Fases
- **P0 — Gate regulatório/negócio (bloqueante).** Consultor regulatório define classe de risco e via
  ANVISA; parceria com radiologista(s)/clínica (CRM); decisão comprar-vs-construir; modelo de
  responsabilidade e consentimento; viabilidade financeira. **Nada técnico de detecção ship antes.**
- **P1 — Teleradiologia MVP, SEM IA (legal hoje).** Paciente envia DICOM → radiologista parceiro lê →
  emite laudo → app explica o laudo (reusa o que já existe). Valida demanda e a operação. Sem
  detecção automática ainda.
- **P2 — IA como apoio ao radiologista.** Integra CADx certificado (opção A) para triagem/realce no
  painel do radiologista. A IA acelera; o laudo continua sendo do médico. Registro vem do fornecedor.
- **P3 — Modelo próprio (opcional, só em escala).** Datasets, validação clínica, registro ANVISA.

## O que muda no app HOJE
- **Nada afrouxa.** O modo imagem segue **não-diagnóstico** até P2 existir. Não ligar detecção via LLM.
- Sem-arrependimento opcional (não conflita): camada de **triagem de urgência não-diagnóstica**
  (detectar marcações/medições → reforçar "leve ao médico com prioridade", sem nomear doença) e copy
  **anti-falsa-tranquilidade** nos filmes. Melhora a utilidade sem cruzar a linha. (Era a opção A da
  decisão; posso implementar em paralelo se quiser.)

## Riscos / honestidade de prazo
- P1 (teleradiologia) é alcançável em **semanas–poucos meses** COM um radiologista parceiro e
  orientação regulatória — é o caminho de valor mais rápido e legal.
- P2 depende do fornecedor de CADx e contratos; meses.
- P3 é multi-anos. Custos de regulatório + jurídico + clínico são significativos em todas as fases.

## Próximos passos concretos (se aprovar a trilha)
1. Conversar com um **consultor regulatório ANVISA** (classe de risco + via) — 1ª prioridade.
2. Mapear **1 radiologista/clínica parceira** disposto a operar a teleradiologia (P1).
3. Levantar **fornecedores de CADx de neuroimagem** com clearance e API (para P2).
4. Eu posso, em paralelo: rascunhar o **stub da interface `radio/detectarAchados()`** (sem provider,
   desligado), o **fluxo de consentimento/LGPD** e o **one-pager** para a conversa com o radiologista.
