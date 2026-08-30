/**
 * Formatacoes de leitura para as telas publicas. Nada aqui decide regra:
 * so traduz numero e data para o jeito que uma pessoa le no Brasil.
 */

export function formatarValor(centavos: number, moeda = 'BRL'): string {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: moeda,
    }).format(centavos / 100);
}

export function formatarData(iso: string): string {
    return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' }).format(new Date(iso));
}

export function formatarDataHora(iso: string): string {
    return new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(iso));
}

/**
 * "1 vaga" / "8 vagas" — o plural certo evita aquele "1 vagas" que denuncia
 * texto montado por maquina.
 */
export function contarVagas(quantidade: number): string {
    return quantidade === 1 ? '1 vaga' : `${quantidade} vagas`;
}

/**
 * "(11) 98888-1111" — o telefone como se escreve num recado.
 *
 * A mascara acompanha a digitacao em vez de esperar o campo perder o foco:
 * quem digita ve o numero se formar e percebe na hora que trocou um digito de
 * lugar. Nove digitos depois do DDD viram celular; oito, telefone fixo.
 *
 * Passa de onze digitos, o resto CAI. Digitando nao da para chegar la — o
 * campo tem `maxlength` —, mas COLANDO da: "+55 11 98888-1111" traz treze e
 * vira "(55) 11988-8811", que e um telefone errado com cara de certo. Nao
 * tratamos o "55" da frente aqui de proposito: seria adivinhar que todo par
 * inicial 55 e codigo de pais, e Sao Paulo interior usa DDD 15, 16, 17...
 * enquanto o Rio Grande do Sul tem 55 como DDD de verdade. Quem colar precisa
 * conferir, e por isso a mascara mostra o resultado enquanto ele se forma.
 */
export function mascararTelefone(valor: string): string {
    const digitos = (valor.match(/\d/g) ?? []).join('').slice(0, 11);

    if (digitos.length <= 2) {
        return digitos;
    }

    const ddd = digitos.slice(0, 2);
    const resto = digitos.slice(2);

    if (resto.length <= 4) {
        return `(${ddd}) ${resto}`;
    }

    // O corte muda de lugar conforme o numero cresce: com ate 8 digitos ele
    // fica no meio (fixo), com 9 ele anda uma casa (celular).
    const corte = resto.length <= 8 ? 4 : 5;

    return `(${ddd}) ${resto.slice(0, corte)}-${resto.slice(corte)}`;
}

/**
 * "123.456.789-09" — o CPF pontuado, so para ler.
 *
 * ATENCAO: isto e mascara de TELA. O valor que viaja ate o servidor continua
 * sendo so digito, porque e ele que vira `documento_hash` e e por esse hash
 * que o sistema descobre inscricao repetida. Se um dia a pontuacao entrar no
 * campo enviado, duas inscricoes do mesmo CPF escritas de jeitos diferentes
 * passariam a ser duas pessoas diferentes aos olhos do banco.
 */
export function mascararCpf(valor: string): string {
    const digitos = (valor.match(/\d/g) ?? []).join('').slice(0, 11);

    return digitos
        .replace(/^(\d{3})(\d)/, '$1.$2')
        .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/^(\d{3})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3-$4');
}

/** Só os dígitos — o que o CPF entrega ao formulário. */
export function apenasDigitos(valor: string): string {
    return (valor.match(/\d/g) ?? []).join('');
}
