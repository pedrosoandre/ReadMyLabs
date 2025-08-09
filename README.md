ReadMyLabs - Plataforma de Análise de Exames e Sintomas
=====================================================

SOBRE O SITE
------------
ReadMyLabs é uma plataforma web desenvolvida para auxiliar usuários na análise educativa de exames laboratoriais (PDF ou imagem) e sintomas clínicos. Utiliza inteligência artificial para fornecer interpretações, possíveis causas, fatores de atenção e recomendações, sempre com linguagem acessível e orientação para buscar um profissional de saúde.

FUNCIONALIDADES PRINCIPAIS
--------------------------
1. **Análise de Exames**
   - O usuário pode enviar um arquivo de exame (PDF ou imagem).
   - O sistema processa o arquivo, extrai o texto (no caso de PDF) e envia para análise por IA.
   - O resultado é apresentado em um template profissional, com opção de baixar em PDF.

2. **Análise de Sintomas**
   - O usuário descreve seus sintomas, duração e intensidade.
   - O sistema envia essas informações para análise por IA.
   - O resultado é apresentado em um template profissional, com opção de baixar em PDF.

3. **Tradução Automática**
   - O site detecta automaticamente o idioma do navegador do usuário.
   - Se o idioma for inglês, ativa o Google Translate para traduzir todo o conteúdo para inglês.

4. **Interface Moderna**
   - Layout responsivo, com fonte Inter e design limpo.
   - Modal para exibição de resultados e botões de download.

DEFESAS E RESTRIÇÕES IMPLEMENTADAS
----------------------------------
1. **Limite de Uma Análise por IP por Dia**
   - Cada endereço IP pode realizar apenas uma análise (exame ou sintomas) por dia.
   - Se o mesmo IP tentar fazer nova análise no mesmo dia, recebe a mensagem: "Você atingiu o limite de 1 análise para hoje. Tente novamente amanhã."
   - O controle é feito por arquivos de registro no servidor, garantindo que o limite seja respeitado mesmo após recarregar a página ou tentar novamente.

2. **Proteção por reCAPTCHA**
   - Antes de enviar qualquer análise, o usuário deve passar pelo Google reCAPTCHA, prevenindo automações e abusos.

3. **Privacidade**
   - Os arquivos enviados são processados temporariamente e não são armazenados permanentemente.
   - Dados sensíveis não são compartilhados com terceiros.

4. **Sem Login Obrigatório**
   - O site não exige cadastro ou login para uso, facilitando o acesso e a experiência do usuário.
   - A restrição de uso é feita apenas pelo IP e pelo reCAPTCHA.

FLUXO DE USO
------------
1. O usuário acessa o site e escolhe entre analisar um exame ou sintomas.
2. Preenche os campos necessários e passa pelo reCAPTCHA.
3. O sistema verifica se o IP já realizou uma análise hoje:
   - Se sim, exibe a mensagem de bloqueio.
   - Se não, processa a análise normalmente.
4. O resultado é exibido em um modal, com opção de baixar em PDF.

ARQUIVOS IMPORTANTES
--------------------
- `index.html`: Página principal, interface de upload e análise.
- `analisar.php`: Backend responsável por processar exames e sintomas, aplicar limite por IP e retornar resultados.
- `style.css`: Estilos visuais do site.
- `README.txt`: Este arquivo de documentação.

OBSERVAÇÕES
-----------
- O site é educativo e não substitui consulta médica.
- O limite de uma análise por IP por dia pode ser ajustado editando o arquivo `analisar.php`.
- Para liberar mais IPs ou remover restrições, basta alterar a lógica no backend.

DÚVIDAS OU SUGESTÕES
--------------------
Entre em contato pelo email: contato@readmylabs.com.br 
