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
